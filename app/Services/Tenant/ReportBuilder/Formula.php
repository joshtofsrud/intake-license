<?php

namespace App\Services\Tenant\ReportBuilder;

/**
 * Formula — evaluates a calculation like "[Revenue] / [Hours worked]".
 * Numbers, + - * / and brackets only; measures are looked up by name.
 * A hand-written parser, never eval(). (MARKER-REPORT-BUILDER-V2)
 */
class Formula
{
    private array $tok = [];
    private int $i = 0;

    /** @return string[] the measure names in a formula */
    public static function tokens(string $f): array
    {
        preg_match_all('/\[([^\]]+)\]/', $f, $m);
        return array_values(array_unique($m[1]));
    }

    /** @param callable(string $label): ?float $valueOf */
    public static function evaluate(string $f, callable $valueOf): ?float
    {
        $bad = false;
        $expr = preg_replace_callback('/\[([^\]]+)\]/', function ($m) use ($valueOf, &$bad) {
            $v = $valueOf($m[1]);
            if ($v === null || !is_finite($v)) { $bad = true; return '0'; }
            return '(' . sprintf('%.10F', $v) . ')';
        }, $f);
        if ($bad || !preg_match('/^[0-9.+\-*\/()\s]+$/', (string) $expr)) {
            return null;
        }
        $p = new self();
        preg_match_all('/\d+(?:\.\d+)?|[+\-*\/()]/', $expr, $mm);
        $p->tok = $mm[0];
        try {
            $v = $p->expr();
            if ($p->i !== count($p->tok)) return null;
            return is_finite($v) ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function expr(): float
    {
        $v = $this->term();
        while (($t = $this->tok[$this->i] ?? null) === '+' || $t === '-') {
            $this->i++;
            $r = $this->term();
            $v = $t === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private function term(): float
    {
        $v = $this->factor();
        while (($t = $this->tok[$this->i] ?? null) === '*' || $t === '/') {
            $this->i++;
            $r = $this->factor();
            if ($t === '/') {
                if ($r == 0.0) throw new \DivisionByZeroError();
                $v /= $r;
            } else {
                $v *= $r;
            }
        }
        return $v;
    }

    private function factor(): float
    {
        $t = $this->tok[$this->i] ?? null;
        if ($t === '-') { $this->i++; return -$this->factor(); }
        if ($t === '+') { $this->i++; return $this->factor(); }
        if ($t === '(') {
            $this->i++;
            $v = $this->expr();
            if (($this->tok[$this->i] ?? null) !== ')') throw new \RuntimeException('bracket');
            $this->i++;
            return $v;
        }
        if ($t !== null && is_numeric($t)) { $this->i++; return (float) $t; }
        throw new \RuntimeException('token');
    }
}
