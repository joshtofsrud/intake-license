<?php

// MARKER-RAISE-MESSAGES
// The eight messages. Placeholders are replaced at send time:
//   {name} {amount} {percent} {cap} {portal} {bank} {account} {routing} {reference} {sender}
return [

    'invitation' => [
        'label'   => 'Invitation',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand when you want someone to see the round',
        'subject' => 'Intake — investment opportunity',
        'body'    => "Hi {name},\n\nI'm raising a small round for Intake, the shop software I've been building. Everything is on one page, including the parts that could go wrong:\n\n{portal}\n\nThe link is personal to you. Happy to talk it through whenever suits.\n\n{sender}",
    ],

    'list_welcome' => [
        'label'   => 'Mailing-list welcome',
        'mode'    => 'automatic',
        'trigger' => 'Someone leaves their details on the shared invitation page',
        'subject' => 'Thanks for the interest in Intake',
        'body'    => "Hi {name},\n\nThanks for leaving your details. I'll be in touch directly — if you have questions before then, just reply to this message.\n\n{sender}",
    ],

    'commitment' => [
        'label'   => 'Commitment received',
        'mode'    => 'automatic',
        'trigger' => 'A commitment amount is recorded against the investor',
        'subject' => 'Your commitment to Intake',
        'body'    => "Hi {name},\n\nNoting your commitment of {amount}, which is {percent} at the {cap} post-money cap.\n\nNothing is owed yet. Paperwork comes next, then wire details.\n\nYour page: {portal}\n\n{sender}",
    ],

    'document_ready' => [
        'label'   => 'Document ready to sign',
        'mode'    => 'automatic',
        'trigger' => 'A document is uploaded and visible to the investor',
        'subject' => 'Your SAFE is ready to sign',
        'body'    => "Hi {name},\n\nThe paperwork is on your page and ready when you are:\n\n{portal}\n\nRead it properly, and take it to your own advisor if you want a second opinion.\n\n{sender}",
    ],

    'signed' => [
        'label'   => 'Signed, with wire instructions',
        'mode'    => 'automatic',
        'trigger' => 'The document is countersigned',
        'subject' => 'Countersigned — wire details inside',
        'body'    => "Hi {name},\n\nSigned on both sides. Wire details for {amount}:\n\nBank: {bank}\nAccount: {account}\nRouting: {routing}\nReference: {reference}\n\nThese details will never change. If you receive an email saying they have, it did not come from me — call before you act on it.\n\n{sender}",
    ],

    'funded' => [
        'label'   => 'Funds received',
        'mode'    => 'automatic',
        'trigger' => 'Funds are marked received',
        'subject' => 'Received — thank you',
        'body'    => "Hi {name},\n\n{amount} arrived. Thank you for backing this.\n\nYour countersigned copy stays on your page: {portal}\n\nI'll write with progress rather than leaving you guessing.\n\n{sender}",
    ],

    'closed' => [
        'label'   => 'Round closed',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand once the round is done',
        'subject' => 'The round is closed',
        'body'    => "Hi {name},\n\nThe round is closed. Your position and documents stay available:\n\n{portal}\n\nFrom here it is the work: shops onboarded, product shipped. I'll keep you posted.\n\n{sender}",
    ],

    'declined' => [
        'label'   => 'Declined',
        'mode'    => 'manual',
        'trigger' => 'Sent by hand when someone passes',
        'subject' => 'Thanks for looking at Intake',
        'body'    => "Hi {name},\n\nUnderstood, and no hard feelings — thanks for taking the time to look.\n\nIf you want the occasional progress note, say the word.\n\n{sender}",
    ],

];
