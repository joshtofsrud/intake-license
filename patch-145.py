#!/usr/bin/env python3
"""
Patch 145 — fix TestSendMail $replyTo readonly collision.

Illuminate\\Mail\\Mailable already declares a non-readonly $replyTo
property. When TestSendMail's constructor used `public readonly ?string
$replyTo` it tried to redeclare with conflicting modifiers and PHP
fatal-errored on instantiation.

Fix: rename the constructor property to $testReplyTo (and update the
envelope() body accordingly). Also remove `readonly` from all four
properties for consistency — they're all conceptually constructor-only
anyway and Mailable doesn't enforce that.

Idempotent.
"""

import argparse
import pathlib
import sys

OLD = '''    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        $env = new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: 'Test email — ' . $this->shopName,
        );
        if ($this->replyTo) {
            $env = new Envelope(
                from: new Address($this->fromEmail, $this->fromName),
                replyTo: [new Address($this->replyTo)],
                subject: 'Test email — ' . $this->shopName,
            );
        }
        return $env;
    }'''

NEW = '''    // MARKER-PATCH-145 — renamed $replyTo to $testReplyTo to avoid Mailable parent collision.
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public ?string $testReplyTo,
        public string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        if ($this->testReplyTo) {
            return new Envelope(
                from: new Address($this->fromEmail, $this->fromName),
                replyTo: [new Address($this->testReplyTo)],
                subject: 'Test email — ' . $this->shopName,
            );
        }
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: 'Test email — ' . $this->shopName,
        );
    }'''


# View also reads $replyTo — needs the same rename.
OLD_VIEW_VAR = """            with: [
                'fromEmail' => $this->fromEmail,
                'fromName'  => $this->fromName,
                'replyTo'   => $this->replyTo,
                'shopName'  => $this->shopName,
            ],"""

NEW_VIEW_VAR = """            with: [
                // MARKER-PATCH-145 — $replyTo -> $testReplyTo; view variable name unchanged.
                'fromEmail' => $this->fromEmail,
                'fromName'  => $this->fromName,
                'replyTo'   => $this->testReplyTo,
                'shopName'  => $this->shopName,
            ],"""


EDITS = [
    ('app/Mail/TestSendMail.php', OLD,          NEW,          'TestSendMail constructor', 'MARKER-PATCH-145 — renamed'),
    ('app/Mail/TestSendMail.php', OLD_VIEW_VAR, NEW_VIEW_VAR, 'TestSendMail content with',  'MARKER-PATCH-145 — $replyTo ->'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    for rel, old, new, label, marker in EDITS:
        p = root / rel
        t = p.read_text()
        if marker in t:
            print(f'already_applied: {label}'); continue
        if old not in t:
            print(f'ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'{"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
