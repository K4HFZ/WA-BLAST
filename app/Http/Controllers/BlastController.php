<?php

namespace App\Http\Controllers;

use App\Models\Blast;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Number;
use App\Models\Schedule;
use App\Models\Tag;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlastController extends Controller
{
    public function histories($campaign_id)
    {
        $blasts = Blast::where('campaign_id', $campaign_id)
            ->whereUserId(Auth::id())
            ->get();
        return view('pages.blast-histories', [
            'histories' => $blasts->all(), //$request->user()->blasts()->latest()->get()
        ]);
    }

    //ajax get page
    public function getPageBlastText(Request $request)
    {
        if ($request->ajax()) {
            return view('ajax.blast.formBlastText', [
                'numbers' => $request->user()
                    ->numbers()
                    ->get(),
                'contacts' => $request->user()
                    ->contacts()
                    ->get(),
                'tags' => $request->user()
                    ->tags()
                    ->get(),
            ])->render();
        }
    }
    public function getPageBlastImage(Request $request)
    {
        if ($request->ajax()) {
            return view('ajax.blast.formBlastImage', [
                'numbers' => $request->user()
                    ->numbers()
                    ->get(),
                'contacts' => $request->user()
                    ->contacts()
                    ->get(),
                'tags' => $request->user()
                    ->tags()
                    ->get(),
            ])->render();
        }
    }
    public function getPageBlastButton(Request $request)
    {
        if ($request->ajax()) {
            return view('ajax.blast.formBlastButton', [
                'numbers' => $request->user()
                    ->numbers()
                    ->get(),
                'contacts' => $request->user()
                    ->contacts()
                    ->get(),
                'tags' => $request->user()
                    ->tags()
                    ->get(),
            ])->render();
        }
    }
    public function getPageBlastTemplate(Request $request)
    {
        if ($request->ajax()) {
            return view('ajax.blast.formBlastTemplate', [
                'numbers' => $request->user()
                    ->numbers()
                    ->get(),
                'contacts' => $request->user()
                    ->contacts()
                    ->get(),
                'tags' => $request->user()
                    ->tags()
                    ->get(),
            ])->render();
        }
    }

    // ajax proccess
    public function blastProccess(Request $request)
    {

        $campaign = $request->user()->campaigns()->whereIn('status', ['waiting', 'processing'])->whereSender($request->sender)->exists();
        if ($campaign) {
            session()->flash('alert', [
                    'type' => 'danger',
                    'msg' =>
                        'there is a campaign with the same sender, please wait until the campaign is finished',
                ]);
                return 'false';
        }
        if ($request->ajax()) {
            if ($request->user()->is_expired_subscription) {
                session()->flash('alert', [
                    'type' => 'danger',
                    'msg' =>
                        'Your subscription has expired. Please renew your subscription.',
                ]);
                return 'false';
            }

            $totalContact = Contact::whereTagId($request->tag)->count();
            if (
                $totalContact == 0 ||
                $totalContact > $request->user()->chunk_blast
            ) {
                session()->flash('alert', [
                    'type' => 'danger',
                    'msg' => 'the number of recipients does not match',
                ]);
                return 'false';
            }

            $numAndMsg = [];
            $cek = Number::whereBody($request->sender)->first();
            if ($cek->status !== 'Connected') {
                session()->flash('alert', [
                    'type' => 'danger',
                    'msg' => 'Your sender is not connected yet!',
                ]);
                return 'false';
            }

            // create text
            switch ($request->type_message) {
                case 'text':
                    $msg = ['text' => $request->message];
                    break;
                case 'image':
                    $arr = explode('.', $request->image);
                    $ext = end($arr);
                    $allowext = ['jpg', 'png', 'jpeg'];
                    if (!in_array($ext, $allowext)) {
                        return response()->json([
                            'status' => 'error',
                            'msg' => 'File type not allowed',
                        ]);
                    }
                    $msg = [
                        'image' => ['url' => $request->image],
                        'caption' => $request->message ?? '',
                    ];
                    break;
                case 'button':
                    if ($request->image) {
                        $arr = explode('.', $request->image);
                        $ext = end($arr);
                        $allowext = ['jpg', 'png', 'jpeg'];
                        if (!in_array($ext, $allowext)) {
                            session()->flash('alert', [
                                'type' => 'danger',
                                'msg' => 'Image type not allowed',
                            ]);
                            return false;
                        }
                    }

                    $buttons = [
                        [
                            'buttonId' => 'id1',
                            'buttonText' => [
                                'displayText' => $request->button1,
                            ],
                            'type' => 1,
                        ],
                    ];
                    // add if exist button2
                    if ($request->button2) {
                        $buttons[] = [
                            'buttonId' => 'id2',
                            'buttonText' => [
                                'displayText' => $request->button2,
                            ],
                            'type' => 1,
                        ];
                    }
                    // add if exist button3
                    if ($request->button3) {
                        $buttons[] = [
                            'buttonId' => 'id3',
                            'buttonText' => [
                                'displayText' => $request->button3,
                            ],
                            'type' => 1,
                        ];
                    }
                    $buttonMessage = [
                        'text' => $request->message,
                        'footer' => $request->footer ?? '',
                        'buttons' => $buttons,
                        'headerType' => 1,
                    ];

                    //add image to buttonMessage if exists
                    if ($request->image) {
                        unset($buttonMessage['text']);
                        $buttonMessage['caption'] = $request->message;
                        $buttonMessage['image'] = ['url' => $request->image];
                        $buttonMessage['headerType'] = 4;
                    }
                    $msg = $buttonMessage;

                    break;
                case 'template':
                    try {
                        if ($request->image) {
                            $arr = explode('.', $request->image);
                            $ext = end($arr);
                            $allowext = ['jpg', 'png', 'jpeg'];
                            if (!in_array($ext, $allowext)) {
                                session()->flash('alert', [
                                    'type' => 'danger',
                                    'msg' => 'Image type not allowed',
                                ]);
                                return false;
                            }
                        }
                        $templateButtons = [];
                        $template1 = $this->makeTemplateButton(
                            $request->template1,
                            1
                        );
                        $templateButtons[] = $template1;
                        // if exist template2
                        if ($request->template2) {
                            $template2 = $this->makeTemplateButton(
                                $request->template2,
                                2
                            );
                            $templateButtons[] = $template2;
                        }
                        // if exist template3
                        if ($request->template3) {
                            $template3 = $this->makeTemplateButton(
                                $request->template3,
                                3
                            );
                            $templateButtons[] = $template3;
                        }

                        $templateMessage = [
                            'text' => $request->message,
                            'footer' => $request->footer ?? '',
                            'templateButtons' => $templateButtons,
                            'viewOnce' => true,
                        ];
                        //add image to templateMessage if exists
                        if ($request->image) {
                            unset($templateMessage['text']);
                            $templateMessage['caption'] = $request->message;
                            $templateMessage['image'] = [
                                'url' => $request->image,
                            ];
                        }
                        $msg = $templateMessage;
                    } catch (\Throwable $th) {
                        Log::error($th->getMessage());
                        session()->flash('alert', [
                            'type' => 'danger',
                            'msg' => 'ups,error occured!',
                        ]);
                        return true;
                    }

                    break;
                case 'list':
                case 'list':
                    if (!$request->list1) {
                        session()->flash('alert', [
                            'type' => 'danger',
                            'msg' => 'Please select a list minimum 1!',
                        ]);
                        return 'false';
                    }

                    $section = [
                        'title' => $request->titlelist,
                    ];
                    $i = 1;
                    $section['rows'][] = [
                        'title' => $request->list1,
                        'rowId' => 1,
                        'description' => '',
                    ];
                    if ($request->list2) {
                        $section['rows'][] = [
                            'title' => $request->list2,
                            'rowId' => 2,
                            'description' => '',
                        ];
                    }
                    if ($request->list3) {
                        $section['rows'][] = [
                            'title' => $request->list3,
                            'rowId' => 3,
                            'description' => '',
                        ];
                    }
                    if ($request->list4) {
                        $section['rows'][] = [
                            'title' => $request->list4,
                            'rowId' => 4,
                            'description' => '',
                        ];
                    }
                    if ($request->list5) {
                        $section['rows'][] = [
                            'title' => $request->list5,
                            'rowId' => 5,
                            'description' => '',
                        ];
                    }
                    // foreach ($request->list as $menu) {
                    //     $i++;
                    //     $section['rows'][] = [
                    //         'title' => $menu,
                    //         'rowId' => 'id' . $i,
                    //         'description' => '',
                    //     ];
                    // }

                    $listMessage = [
                        'text' => $request->message,
                        'footer' => $request->footer ?? '',
                        'title' => $request->namelist,
                        'buttonText' => $request->buttonlist,
                        'sections' => [$section],
                    ];

                    $msg = $listMessage;
                    break;

                default:
                    # code...
                    break;
            }

            $data = [];
            $campaign = Campaign::create([
                'user_id' => $request->user()->id,
                'sender' => $request->sender,
                'name' => $request->name,
                'tag' => $request->tag,
                'type' => $request->type_message,
                'message' => json_encode($msg),
                'status' => 'waiting',
                'schedule' => $request->start_date ?? now(),
            ]);

            $contacts = Contact::whereTagId($request->tag)->get();
            foreach ($contacts as $contact) {
                // replace {name} with contact name
              
                   
                    try {
                        //code...
                        $message = str_replace('{name}', $contact->name, $msg);
                    } catch (\Throwable $th) {
                        $message = $msg;
                    }

                    $data[] = [
                        'campaign_id' => $campaign->id,
                        'user_id' => $request->user()->id,
                        'sender' => $request->sender,
                        'receiver' => $contact->number,
                        'message' => json_encode($message),
                        'type' => $request->type_message,
                        'status' => 'pending',
                        'created_at' => now(),
                    ];
                
            }
            // insert to database
            
            $campaign->blasts()->createMany($data);
            //
            // insert many to blast table, with receiver = in destination

          
                $campaign->update([
                    'status' => 'waiting',
                ]);
                session()->flash('alert', [
                    'type' => 'success',
                    'msg' => 'Blast message scheduled successfully',
                ]);
                return true;
           
        }
    }

    public function sendBlast($data, $delay, $campaign)
    {
        try {
            //code...
            return Http::withOptions(['verify' => false])
                ->asForm()
                ->post(env('WA_URL_SERVER') . '/backend-blast', [
                    'data' => json_encode($data),
                    'delay' => $delay,
                ]);
        } catch (\Throwable $th) {
            $campaign->delete();
            session()->flash('alert', [
                'type' => 'danger',
                'msg' => 'There is trouble in your node server',
            ]);
            return false;
        }
    }
    public function getAllnumbers()
    {
        $contacts = $request->user()
            ->contacts()
            ->get();
        $numbers = [];
        foreach ($contacts as $contact) {
            $numbers[] = $contact->number;
        }

        return $numbers;
    }

    public function getNumberbyTag($tag)
    {
        $contacts = Tag::find($tag)
            ->contacts()
            ->get();
        $numbers = [];
        foreach ($contacts as $contact) {
            $numbers[] = $contact->number;
        }

        return $numbers;
    }

    public function makeTemplateButton($templateButton, $no)
    {
        $allowType = ['callButton', 'urlButton'];
        $template = $templateButton;
        $type = explode('|', $template)[0] . 'Button';
        $text = explode('|', $template)[1];
        $urlOrNumber = explode('|', $template)[2];

        if (!in_array($type, $allowType)) {
            return redirect(route('autoreply'))->with('alert', [
                'type' => 'danger',
                'msg' => 'The Templates are not valid!',
            ]);
        }

        $typePurpose =
            explode('|', $template)[0] === 'url' ? 'url' : 'phoneNumber';
        return [
            'index' => $no,
            $type => ['displayText' => $text, $typePurpose => $urlOrNumber],
        ];
    }

    public function scheduled()
    {
        $scheduled = Schedule::whereUserId($request->user()->id)
            ->latest()
            ->get();
        return view('pages.scheduled-lists', [
            'schedule' => $scheduled,
        ]);
    }
}
