<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\InboundProcessor;
use App\Domain\Messaging\NotificationQueue;
use App\Jobs\SendOutboundNotification;
use App\Models\Conversation;
use App\Models\OutboundNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends StaffController
{
    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        $conversations = Conversation::query()
            ->where('merchant_id', $merchant->id)
            ->with('customer', 'latestMessage')
            ->withMax('messages', 'wa_timestamp')
            ->orderByDesc('messages_max_wa_timestamp')
            ->get();

        return view('inbox.index', compact('conversations'));
    }

    public function start(Request $request, InboundProcessor $processor): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'body' => ['required', 'string', 'max:1000'],
            'sent_at' => ['nullable', 'date'],
        ]);
        $sentAt = isset($data['sent_at'])
            ? CarbonImmutable::parse($data['sent_at'], $merchant->timezone)
            : CarbonImmutable::now($merchant->timezone);
        $message = $processor->simulate($merchant, $data['phone'], $data['body'], $sentAt);
        if ($message === null) {
            return back()->withErrors(['phone' => 'This workspace has no WhatsApp number yet.']);
        }

        return redirect()->route('inbox.show', $message->conversation_id);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->guard($request, $conversation);
        $conversation->load('customer');
        $messages = $conversation->messages()->orderBy('wa_timestamp')->get();
        $outbound = $conversation->outbound()->orderBy('id')->get();
        $proposals = $conversation->proposals()->orderByDesc('id')->get();
        $handoffs = $conversation->handoffs()->latest('id')->get();

        return view('inbox.show', compact('conversation', 'messages', 'outbound', 'proposals', 'handoffs'));
    }

    public function simulate(Request $request, Conversation $conversation, InboundProcessor $processor): RedirectResponse
    {
        $this->guard($request, $conversation);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'sent_at' => ['nullable', 'date'],
        ]);
        $merchant = $this->merchant($request);
        $sentAt = isset($data['sent_at'])
            ? CarbonImmutable::parse($data['sent_at'], $merchant->timezone)
            : CarbonImmutable::now($merchant->timezone);
        $processor->simulate($merchant, $conversation->customer->phone_e164, $data['body'], $sentAt);

        return back()->with('status', 'Customer message received.');
    }

    public function takeover(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->guard($request, $conversation);
        $conversation->update([
            'status' => 'staff',
            'taken_over_by' => $request->user()->id,
            'taken_over_at' => now(),
        ]);

        return back()->with('status', 'You have this chat. Automated replies are paused.');
    }

    public function resume(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->guard($request, $conversation);
        $conversation->update(['status' => 'automated', 'taken_over_by' => null, 'taken_over_at' => null]);

        return back()->with('status', 'Automation resumed.');
    }

    public function reply(Request $request, Conversation $conversation, NotificationQueue $queue): RedirectResponse
    {
        $this->guard($request, $conversation);
        $body = $request->validate(['body' => ['required', 'string', 'max:1000']])['body'];
        $queue->deliver(
            merchant: $conversation->merchant,
            customer: $conversation->customer,
            conversation: $conversation,
            body: $body,
            proactive: false,
            template: null,
            idempotencyKey: 'staff-'.$conversation->id.'-'.now()->timestamp.'-'.$request->user()->id,
            source: 'staff',
        );

        return back()->with('status', 'Reply sent.');
    }

    public function retry(Request $request, OutboundNotification $notification): RedirectResponse
    {
        abort_unless($notification->merchant_id === $this->merchant($request)->id, 404);
        abort_unless(in_array($notification->status, ['failed', 'blocked'], true), 422);
        $notification->update(['status' => 'queued', 'failure_reason' => null]);
        SendOutboundNotification::dispatchSync($notification->id);

        return back()->with('status', 'Send retried.');
    }

    private function guard(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->merchant_id === $this->merchant($request)->id, 404);
    }
}
