<?php

namespace App\Console\Commands;

use App\Models\Blast;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Number;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Polyfill\Intl\Idn\Info;

class StartBlast extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'start:blast';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $waitingCampaigns = Campaign::where('status', 'waiting')
            ->orWhere('status', 'processing')
            ->where('schedule', '<=', now())
            ->get();

        foreach ($waitingCampaigns as $campaign) {
            $campaign = $campaign;
            $isSenderConnected = Number::where('body', $campaign->sender)
                ->where('status', 'connected')
                ->exists();

            if ($isSenderConnected) {
                $campaign->status = 'processing';
                $campaign->save();

                $isCampaignNotPaused = Blast::where(
                    'campaign_id',
                    $campaign->id
                )
                    ->where('status', '!=', 'paused')
                    ->exists();
                // recursive function

                function checkBlastPending($campaign, $isCampaignNotPaused)
                {
                    $statusCampaign = Campaign::where('id', $campaign->id)
                        ->first()
                        ->status;
                    
                    if ($statusCampaign == 'paused') {
                        return;
                    }
                    $data = [];
                   // $get = rand(30, 50);
                    $blastcount = Blast::where('campaign_id', $campaign->id)
                        ->where('status', 'pending')
                        ->count();
                    if ($blastcount > 0) {
                        $blasts = Blast::where('campaign_id', $campaign->id)
                            ->where('status', 'pending')
                            ->limit(30)
                            ->get();
                        foreach ($blasts as $blast) {
                            // if exist {name} in message
                            if (
                                strpos($campaign->message, '{name}') !== false
                            ) {
                                $name = Contact::whereNumber(
                                    $blast->receiver
                                )->first()->name;
                                $message = str_replace(
                                    '{name}',
                                    $name,
                                    $campaign->message
                                );
                            } else {
                                $message = $campaign->message;
                            }
                            $data[] = [
                                'campaign_id' => $campaign->id,
                                'receiver' => $blast->receiver,
                                'message' => $message,
                                'sender' => $campaign->sender,
                            ];
                        }

                        try {
                            $proc = Http::withOptions(['verify' => false])
                                ->asForm()
                                ->post(
                                    env('WA_URL_SERVER') . '/backend-blast',
                                    [
                                        'data' => json_encode($data),
                                        'delay' => 1,
                                    ]
                                );
                         
                                
                             $result = json_decode($proc->body());
                             $successNumber = $result->success;
                             $failedNumber = $result->failed;
                             Log::info($proc);
                              Blast::whereIn('receiver', $successNumber)->whereStatus('pending')->update(['status' => 'success']);
                              Blast::whereIn('receiver', $failedNumber)->whereStatus('pending')->update(['status' => 'failed']);
                          
                            $data = [];
                          
                            checkBlastPending($campaign, $isCampaignNotPaused);
                        } catch (\Throwable $th) {
                            Log::info($th);
                            // if in blasts still have status pending change status to finish
                            $blasts->each(function ($item) {
                                $item->status = 'failed';
                                $item->save();
                            });

                            // reset $data
                            $data = [];
                            checkBlastPending($campaign, $isCampaignNotPaused);
                        }

                    } else {
                      
                    }
                }

                checkBlastPending($campaign, $isCampaignNotPaused);


                if ($isCampaignNotPaused && Blast::where('campaign_id', $campaign->id)->where('status', 'pending')->count() == 0) {
                    $campaign->status = 'finish';
                    $campaign->save();
                }
            }
        }

        return 0;
    }
}
