<?php

namespace Goldnead\Messaging\Http\Controllers\Cp;

use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Scopes\Filters\ConversationFilter;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Facades\Scope;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

/**
 * A moderator's view of the conversations, and only of their shape: when, who
 * on which side, how many messages, which flags. The CP never reads or ships a
 * message body, a participant's name or key, or the free-text id of a subject.
 * Both screens assemble their payload field by field for that reason: handing a
 * model to Inertia would serialise whatever the table carries.
 *
 * `index()` answers the Inertia page and the JSON the native <Listing> fetches
 * on every sort, filter and page change, like core's own listings.
 */
class ConversationController extends Controller
{
    use QueriesFilters;

    private const SORTABLE = ['id', 'created_at', 'last_message_at', 'messages_count'];

    private const MAX_PER_PAGE = 500;

    public function index(FilteredRequest $request)
    {
        Gate::authorize('view messaging');

        // Before the migrations have run there is nothing to query. Say so on
        // the page and in the log instead of answering HTTP 500.
        $ready = Schema::hasTable('conversations') && Schema::hasTable('messages') && Schema::hasTable('conversation_participants');

        if (! $ready) {
            Log::warning('Messaging: the Control Panel needs its tables. Run `php artisan migrate`.');

            return $request->wantsJson() ? $this->emptyListing() : $this->page(false, false);
        }

        if ($request->wantsJson()) {
            return $this->listing($request);
        }

        return $this->page(true, Conversation::query()->exists());
    }

    private function page(bool $ready, bool $hasAny)
    {
        return Inertia::render('messaging::Index', [
            'setupRequired' => ! $ready,
            'columns' => collect($this->columns())->map->toArray()->all(),
            'filters' => Scope::filters(ConversationFilter::LISTING_KEY),
            'hasAny' => $hasAny,
            'listingUrl' => cp_route('messaging.index'),
            'perPage' => $this->perPage(null),
        ]);
    }

    public function show(Conversation $conversation)
    {
        Gate::authorize('view messaging');

        $participants = $conversation->participants()->orderBy('id')->get();
        $initiatorKey = $participants->firstWhere('role', ConversationParticipant::ROLE_INITIATOR)?->user_id;
        $reported = $participants->contains('flagged', true);

        $timeline = $conversation->messages()
            ->select(['id', 'conversation_id', 'sender_user_id', 'hidden', 'hidden_reason', 'hidden_at', 'created_at'])
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'sent_at' => $message->created_at?->format('Y-m-d H:i:s'),
                'side' => match (true) {
                    $message->sender_user_id === null => 'deleted',
                    (string) $message->sender_user_id === (string) $initiatorKey => 'initiator',
                    default => 'recipient',
                },
                'hidden' => $message->hidden,
                'hidden_reason' => $message->hidden ? $message->hidden_reason : null,
                'hidden_at' => $message->hidden_at?->format('Y-m-d H:i:s'),
                'hide_url' => cp_route('messaging.messages.hide', ['message' => $message->id]),
                'unhide_url' => cp_route('messaging.messages.unhide', ['message' => $message->id]),
            ])
            ->all();

        // Sides, never keys or names. The recipient side is numbered so two
        // blocked members can be told apart without saying who they are.
        $number = 0;
        $parties = $participants->map(fn (ConversationParticipant $participant) => [
            'id' => $participant->id,
            'side' => $participant->role === ConversationParticipant::ROLE_INITIATOR ? 'initiator' : 'recipient',
            'number' => $participant->role === ConversationParticipant::ROLE_INITIATOR ? null : ++$number,
            'blocked' => $participant->blocked,
            'flagged' => $participant->flagged,
            'archived' => $participant->archived,
            'unblock_url' => cp_route('messaging.participants.unblock', ['participant' => $participant->id]),
        ])->all();

        return Inertia::render('messaging::Show', [
            'conversation' => [
                'id' => $conversation->id,
                'created_at' => $conversation->created_at?->format('Y-m-d H:i:s'),
                'last_message_at' => $conversation->last_message_at?->format('Y-m-d H:i:s'),
                'recipient' => $this->recipient($conversation),
                'anonymous' => $conversation->anonymous,
                'profile_shared' => $conversation->profile_shared,
                'subject_type' => $conversation->subject_type,
                'reported' => $reported,
                'message_count' => count($timeline),
            ],
            'timeline' => $timeline,
            'participants' => $parties,
            'canManage' => (bool) auth()->user()?->can('manage messaging'),
            'backUrl' => cp_route('messaging.index'),
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyListing(): array
    {
        return [
            'data' => [],
            'meta' => [
                'columns' => collect($this->columns())->map->toArray()->all(),
                'activeFilterBadges' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $this->perPage(null),
                'from' => null,
                'to' => null,
                'total' => 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function listing(FilteredRequest $request): array
    {
        $query = Conversation::query()
            ->with('recipientTeam:id,name')
            ->withCount('messages')
            ->withExists([
                'participants as is_flagged' => fn ($q) => $q->where('flagged', true),
                'participants as is_blocked' => fn ($q) => $q->where('blocked', true),
                'messages as has_hidden' => fn ($q) => $q->where('hidden', true),
            ]);

        $badges = $this->queryFilters(
            $query,
            $request->filters ?? [],
            ['handle' => ConversationFilter::LISTING_KEY],
        );

        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at';
        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy($sort, $order)
            ->orderBy('id', $order)
            ->paginate($this->perPage($request->input('perPage')))
            ->withQueryString();

        return [
            'data' => collect($paginator->items())->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'created_at' => $conversation->created_at?->format('Y-m-d H:i:s'),
                'last_message_at' => $conversation->last_message_at?->format('Y-m-d H:i:s'),
                'recipient' => $this->recipient($conversation),
                'anonymous' => $conversation->anonymous,
                'messages_count' => (int) $conversation->getAttribute('messages_count'),
                'is_flagged' => (bool) $conversation->getAttribute('is_flagged'),
                'is_blocked' => (bool) $conversation->getAttribute('is_blocked'),
                'has_hidden' => (bool) $conversation->getAttribute('has_hidden'),
                'show_url' => cp_route('messaging.show', ['conversation' => $conversation->id]),
            ])->all(),
            'meta' => [
                'columns' => collect($this->columns())->map->toArray()->all(),
                'activeFilterBadges' => $badges,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** The team's name, or "Person": a person recipient is never named. */
    private function recipient(Conversation $conversation): string
    {
        return $conversation->recipient_team_id === null
            ? __('messaging::cp.person')
            : (string) ($conversation->recipientTeam->name ?? __('messaging::cp.team'));
    }

    private function perPage(mixed $requested): int
    {
        $perPage = (int) ($requested ?: 25);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /** @return array<int, Column> */
    private function columns(): array
    {
        return [
            Column::make('id')->label(__('messaging::cp.col_id')),
            Column::make('created_at')->label(__('messaging::cp.col_created')),
            Column::make('last_message_at')->label(__('messaging::cp.col_last_activity')),
            Column::make('recipient')->label(__('messaging::cp.col_recipient'))->sortable(false),
            Column::make('anonymous')->label(__('messaging::cp.col_anonymous'))->sortable(false),
            Column::make('messages_count')->label(__('messaging::cp.col_messages')),
            Column::make('flags')->label(__('messaging::cp.col_flags'))->sortable(false),
        ];
    }
}
