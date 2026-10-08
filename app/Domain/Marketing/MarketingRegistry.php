<?php

namespace App\Domain\Marketing;

/**
 * §11 — the message bodies a company starts with.
 *
 * Not demo data: a company switching marketing on has to write *something*
 * before a campaign can exist, and an empty template list is a dead end rather
 * than a blank page. These are the four messages a Bangladeshi trading company
 * sends most — an offer, a newsletter, a WhatsApp promotion and a win-back — in
 * the shape the desk actually fills in, with the placeholders the renderer
 * understands.
 *
 * They are materialised only for a company that has none of its own, so a body
 * somebody has edited is never overwritten by a later visit to the desk — the
 * same rule the utility provider registry follows. Editing one is normal: the
 * point is that the first campaign does not begin with an empty text box.
 */
class MarketingRegistry
{
    /**
     * @var array<int, array{code:string, channel:string, name:string, subject:?string, body:string}>
     */
    public const TEMPLATES = [
        [
            'code' => 'MKT-OFFER-SMS',
            'channel' => 'sms',
            'name' => 'This month’s offer',
            'subject' => null,
            'body' => 'Dear {customer}, {company} has this month’s offer ready for you. Reply YES and we will send the details, or STOP to opt out.',
        ],
        [
            'code' => 'MKT-NEWSLETTER-EMAIL',
            'channel' => 'email',
            'name' => 'Monthly newsletter',
            'subject' => '{company} — what is new this month',
            'body' => "Dear {customer},\n\nHere is what is new at {company} this month — new stock, current prices and the offers running until the end of {month}.\n\nYou are receiving this because you are a customer. Reply to this message and we will take you off the list.",
        ],
        [
            'code' => 'MKT-PROMO-WHATSAPP',
            'channel' => 'whatsapp',
            'name' => 'WhatsApp promotion',
            'subject' => null,
            'body' => '{customer}, {company} has news for you this {month}. Reply STOP to opt out.',
        ],
        [
            'code' => 'MKT-WINBACK-SMS',
            'channel' => 'sms',
            'name' => 'We have not seen you for a while',
            'subject' => null,
            'body' => 'Dear {customer}, we have not seen you for a while at {company}. Ask for our {month} offer when you come back, or reply STOP to opt out.',
        ],
    ];

    /**
     * The campaign presets the menu leans on: a leaf that promises “Win-Back
     * Campaigns” or “Review Requests” opens the campaign desk with the audience
     * and the note that make that job concrete, rather than a second screen that
     * would be the campaign desk with a different title.
     *
     * @var array<string, array{title:string, audience:string, note:string, icon:string}>
     */
    public const PRESETS = [
        'win-back' => [
            'title' => 'Win-back campaigns',
            'audience' => 'dormant',
            'icon' => 'bi-arrow-counterclockwise',
            'note' => 'The win-back list is customers whose last invoice is older than the window — everybody who has stopped buying, worked out from their own invoice history rather than from a list somebody typed months ago.',
        ],
        'review' => [
            'title' => 'Review requests',
            'audience' => 'recent_buyers',
            'icon' => 'bi-star',
            'note' => 'A review request goes to the people who have just bought, while the delivery is still on their mind. The feedback they give is recorded against the customer, on the customer page — this desk sends the ask, it does not grade the answer.',
        ],
        'nps' => [
            'title' => 'Customer feedback and NPS',
            'audience' => 'recent_buyers',
            'icon' => 'bi-chat-square-text',
            'note' => 'Ask the people who bought most recently how it went. What comes back is kept on the customer record; this application does not score it for you, and there is no survey engine behind this list.',
        ],
        'referral' => [
            'title' => 'Referral campaigns',
            'audience' => 'all',
            'icon' => 'bi-person-plus',
            'note' => 'Ask your customers to bring somebody. Referrals are recorded against the customer who made them, on the customer page — so a referral campaign is a message, and the reward for it is a decision you make, not one this desk invents.',
        ],
    ];

    /**
     * The capabilities this application deliberately does not have, and what it
     * does instead.
     *
     * The catalogue has leaves for pixels, conversions APIs, ad platforms, open
     * tracking, click tracking, drip journeys, A/B tests and chat inboxes. None of
     * those can be built honestly here: there is no third-party credential in this
     * deployment, no tracking pixel is emitted, no click is shortened, and no
     * social account is connected. A screen that showed those features with empty
     * charts would be worse than useless — it would be a claim that we measure
     * something we do not measure.
     *
     * So each of those leaves opens the page below: what it is, why it is not here,
     * and which real screen to use for the part of the job that *can* be done.
     * Nothing on that page is invented either.
     *
     * @var array<string, array{title:string, eyebrow:string, summary:string, details:list<string>, instead:?array{0:string, 1:string, 2?:array<string,string>}}>
     */
    public const CAPABILITIES = [
        'meta-platform' => [
            'title' => 'Meta pixel, conversions API and ad platforms',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'This application does not talk to Meta. There is no pixel on any page, no conversions API client and no ad account connection, so there is no purchase event to send and no campaign spend to import.',
            'details' => [
                'No tracking pixel is emitted anywhere in this application, including the storefront-facing pages it does serve.',
                'No access token for an ad account exists in this deployment, and credentials are never collected by a screen that cannot use them.',
                'Advertising spend therefore never appears in the cost figures — the marketing desk counts the message price it actually pays, and says so on the report.',
                'A product catalog feed would need a public storefront this application does not have; products are sold through the counter, not through a catalogue endpoint.',
            ],
            'instead' => ['marketing.reports', 'See what the campaigns you *can* send actually cost and brought in'],
        ],
        'google-platform' => [
            'title' => 'Google Ads and Analytics 4',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'Analytics 4 is a measurement ID and a script on the public site; Google Ads is an ad account. Neither exists here, so there is no analytics property to report from and no ad spend to bring in.',
            'details' => [
                'No measurement ID is configured or shipped, and no analytics script is embedded.',
                'Nothing in this application reads an Analytics or Ads reporting API, so no visitor, session or conversion figure is shown — invented ones would be worse than absent ones.',
                'If a measurement ID is added by the operator, the honest place for it is the settings screen, not a report that pretends to have the numbers already.',
            ],
            'instead' => ['marketing.reports', 'Read the marketing figures that come from this application’s own rows'],
        ],
        'tiktok-platform' => [
            'title' => 'TikTok pixel and ad reporting',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'There is no TikTok pixel, no events API client and no ads connection in this application.',
            'details' => [
                'No script is injected into any page for TikTok, and no event is posted to its API.',
                'Ad spend and attribution are therefore absent from every figure in this desk, deliberately.',
            ],
            'instead' => ['marketing.campaigns.index', 'Run the part of the campaign this application does own — the message'],
        ],
        'social-media' => [
            'title' => 'Social posting and social reporting',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'No social account is connected, so nothing here posts, schedules or measures anything on Facebook, Instagram or TikTok.',
            'details' => [
                'The social schedule in this application is the schedule of *messages* — SMS, email, WhatsApp and push — that are on the clock; it is not a social calendar.',
                'Social reports would need platform data this application never receives.',
            ],
            'instead' => ['marketing.campaigns.index', 'See what is on the clock to go out, and when'],
        ],
        'open-and-click-tracking' => [
            'title' => 'Open tracking and click tracking',
            'eyebrow' => 'Deliberately absent',
            'summary' => 'This application sends no tracking pixel and rewrites no link, so it knows exactly two things about an email: whether a transport accepted it, and whether the transport later reported a failure.',
            'details' => [
                '“Sent” means a configured transport took the message, not that a human opened it — no provider callback is wired up to say more.',
                'Links in messages are left as you wrote them; nothing is shortened, redirected or counted.',
                'A delivery state is therefore never dressed up as engagement. The outbox holds the truth: queued, held, sent or failed, with the provider’s own error when there is one.',
            ],
            'instead' => ['marketing.deliveries', 'Read what each message actually did'],
        ],
        'sequences-and-drips' => [
            'title' => 'Email sequences and drip campaigns',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'A drip is a journey: a message, a wait, a branch, another message. This desk sends one campaign to one audience at one moment, and does not pretend to run a journey.',
            'details' => [
                'Nothing here schedules a second message off the first one being read, because reading is not measured.',
                'What can be done honestly: two planned campaigns on different dates, each with its own audience rule — the win-back list and the recent-buyer list are different audiences anyway.',
                'A campaign that is on the clock is listed on the campaign desk with the time it will go out.',
            ],
            'instead' => ['marketing.campaigns.index', 'Write the next message and put it on the clock'],
        ],
        'ab-testing' => [
            'title' => 'A/B testing',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'Splitting an audience means measuring which half did better, and the only outcome this application measures is orders placed by recipients inside the attribution window. That is a real measure — but it is not what an A/B test of copy needs to be honest.',
            'details' => [
                'There is no variant field on a campaign and no split engine, so no campaign is secretly two campaigns.',
                'If you want to compare two wordings, send one this week and one the next, to the same audience rule, and compare the two rows on the cost-against-revenue report — which is a comparison you can check.',
            ],
            'instead' => ['marketing.reports', 'Compare two campaigns side by side'],
        ],
        'read-receipts' => [
            'title' => 'WhatsApp read receipts',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'There is no WhatsApp transport in this application at all: no business account, no API client, no credentials. WhatsApp campaigns are recorded, prepared and held — and they say “held”, not “sent”.',
            'details' => [
                'Read receipts come from a provider that has actually delivered a message; since no message leaves here by WhatsApp, there is nothing to receipt.',
                'When a real WhatsApp provider is configured by an operator, the messages already sitting in the outbox as held become sendable — the audience, the wording and the costs are all recorded and waiting.',
            ],
            'instead' => ['marketing.deliveries', 'See the WhatsApp messages that are held and why', ['channel' => 'whatsapp']],
        ],
        'whatsapp-chat' => [
            'title' => 'WhatsApp chat inbox',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'An inbox needs a two-way channel. This application has none for WhatsApp: it can compose a WhatsApp campaign, but it cannot deliver one, and it has no webhook to receive a reply.',
            'details' => [
                'SMS and email are one-way here too — the opt-out register records a reply a human read, it does not listen for one.',
                'This desk records what happens to messages it hands to a transport; it does not chat.',
            ],
            'instead' => ['marketing.optouts', 'Record what a customer asked for, by hand, so the machine honours it'],
        ],
        'sms-balance' => [
            'title' => 'SMS balance',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'A credit balance belongs to the gateway, not to us. This application stores which provider is active and whether it is configured — one encrypted credential — and nothing else.',
            'details' => [
                'There is no balance API call anywhere, so no figure is shown; a number typed in by hand would go stale silently and be trusted anyway.',
                'What the desk does know is how many messages were handed over and what each one cost — that is on the cost-against-revenue report.',
            ],
            'instead' => ['marketing.deliveries', 'See how many SMS messages were handed over, held or failed', ['channel' => 'sms']],
        ],
        'triggered-messages' => [
            'title' => 'Triggered and event-based messages',
            'eyebrow' => 'Lives with the event, not with marketing',
            'summary' => 'This application does send messages off events — an order confirmation, a delivery note, a bill reminder, a compliance alert — but each of those belongs to the desk that owns the event, not to a marketing trigger builder.',
            'details' => [
                'A marketing trigger builder would let somebody write a rule that messages customers off a condition nobody has reviewed — which is how people end up written to twice for the same order.',
                'Transactional messages are queued by the sales, purchase, utility and compliance desks, with the same truthful outbox behind them: queued when a transport exists, held when one does not.',
                'If you want to write to people about something that has not happened yet, the tool is a scheduled campaign — the audience rule is re-read on the day it goes out.',
            ],
            'instead' => ['notifications.index', 'See the notifications this application sends on events'],
        ],
        'abandoned-cart' => [
            'title' => 'Abandoned cart recovery',
            'eyebrow' => 'Not applicable — there is no cart',
            'summary' => 'This application has no shopping cart to abandon. Sales happen at the counter, on a held bill, or on an invoice raised at the desk; a POS bill that is put on hold is a shop’s own arrangement, not a basket on a website.',
            'details' => [
                'There is no cart table, no cart session and no public checkout, so there is nothing to recover and no time-to-recover to measure.',
                'The nearest honest equivalent is a campaign to the customers who bought recently — or a look at the due list, where customers who owe money actually are.',
            ],
            'instead' => ['marketing.campaigns.index', 'Write to the customers who do buy'],
        ],
        'birthday-and-anniversary' => [
            'title' => 'Birthday and anniversary campaigns',
            'eyebrow' => 'Not buildable yet — the date is not held',
            'summary' => 'A birthday campaign needs a birthday. This application does not hold a date of birth or a customer anniversary for anybody, so an audience rule for “birthdays this month” would match nobody — and a cheerful empty list is worse than saying so.',
            'details' => [
                'The customer record holds the name, contacts, credit terms and tax details it needs to trade — no personal dates.',
                'If those dates are added to the customer master by the operator, the audience rules to use them belong here, in the campaign desk, where the rest of the audience rules already live.',
                'Until then the “bought recently” and “gone quiet” rules are the dated ones that rest on data this application actually has: invoice history.',
            ],
            'instead' => ['marketing.contacts', 'See who a campaign can actually reach today'],
        ],
        'google-review' => [
            'title' => 'Google review requests',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'Asking for a Google review means handing somebody a link to a listing this application does not own or know. There is no Places connection and no review link stored anywhere.',
            'details' => [
                'What can be done honestly: a review-request campaign to the customers who just bought, in your own words, with whatever link you want to paste into the message body.',
                'What comes back is recorded against the customer record as feedback, by whoever reads it.',
            ],
            'instead' => ['marketing.campaigns.index', 'Write a review request with your own link in it'],
        ],
        /* §11 leads, affiliates and influencers. None of the three has a record in
         * this application — there is no enquiry, no affiliate account and no
         * collaboration — so each leaf opens the honest page that says so and
         * points at the real screen that does part of the same job, rather than a
         * pipeline of cards nobody entered. */
        'lead-pipeline' => [
            'title' => 'Leads, the pipeline and lead scoring',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'This application has no lead record. There is no enquiry table, no pipeline stage, no follow-up reminder, no response-time clock and no scoring model, so a pipeline screen could only show cards nobody entered.',
            'details' => [
                'Nothing here writes a lead: a person becomes a customer at the counter, on the phone or through an import, and from that moment their ledger is the record.',
                'A follow-up needs a date on a lead and a response time needs the clock to start on the enquiry — this application records neither, so both figures would be invented.',
                'A conversion rate needs a link from a lost lead to the customer who eventually bought; that link does not exist, and a made-up percentage would be read as a fact.',
                'Lead scoring is a model over lead fields (source, budget, urgency). With no lead fields there is nothing to score, and a score of zero on every row is not a scoring engine.',
            ],
            'instead' => ['customers.index', 'See the customers you did win, with their real buying history'],
        ],
        'affiliate-tracking' => [
            'title' => 'Affiliates, referral codes and commissions',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'There are no affiliate accounts here, no code issued per affiliate, no commission ledger and no payout run. The one code this application can attribute a sale to is a coupon.',
            'details' => [
                'A coupon is a real code with a real redemption count, taken against real invoices — that is the part of an affiliate programme this application can do honestly today.',
                'A commission is a payable owed to a named affiliate. Money movement exists, but there is no affiliate to owe it to, so a commission total would be a number with nothing behind it.',
                'An affiliate report normally leads with clicks and sign-ups; neither is measured here, and importing them would need a tracking link on a public site this application does not serve.',
            ],
            'instead' => ['sales.coupons.index', 'Issue a code and read how it was really used'],
        ],
        'influencer-campaigns' => [
            'title' => 'Influencers, collaborations and their reach',
            'eyebrow' => 'Not built — and not faked',
            'summary' => 'There are no influencer profiles, no collaboration records, no agreed fee per post and no reach or engagement figures — those come from the platforms, which this application does not read.',
            'details' => [
                'Reach, impressions and engagement are platform numbers. Nothing in this application fetches them, and a hand-typed estimate would be indistinguishable from a measurement once it is on a report.',
                'A collaboration needs an agreed deliverable, a date and a fee: the documents shelf can store the contract, but nothing here follows a post from agreed to published.',
                'What is real is a campaign to your own customers, measured by the invoices they raise inside the attribution window — including the referral preset built for exactly this ask.',
            ],
            'instead' => ['marketing.campaigns.index', 'Send a referral-preset campaign and measure the invoices it raises', ['preset' => 'referral']],
        ],

    ];
}
