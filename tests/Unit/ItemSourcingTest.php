<?php

namespace Tests\Unit;

use App\Services\Tenant\ItemSourcing;
use PHPUnit\Framework\TestCase;

/** MARKER-DISTRIBUTION-WAREHOUSES: where a wanted quantity comes from. No database. */
class ItemSourcingTest extends TestCase
{
    private function wh(string $id, int $qty): array { return ['id' => $id, 'name' => strtoupper($id), 'qty' => $qty]; }

    public function test_shop_covers_everything(): void
    {
        $p = ItemSourcing::plan(3, 5, [$this->wh('a', 9)]);
        $this->assertSame([['source' => 'shop', 'qty' => 3]], $p['segments']);
        $this->assertSame([0, 0], [$p['special'], $p['blocked']]);
    }

    public function test_own_first_splits_the_shortfall_to_a_supplier(): void
    {
        $p = ItemSourcing::plan(3, 2, [$this->wh('a', 9)], ['ownFirst' => true]);
        $this->assertSame(['shop', 'supplier'], array_column($p['segments'], 'source'));
        $this->assertSame([2, 1], array_column($p['segments'], 'qty'));
    }

    public function test_own_first_off_ships_the_whole_line_from_the_supplier(): void
    {
        $p = ItemSourcing::plan(3, 2, [$this->wh('a', 9)], ['ownFirst' => false]);
        $this->assertSame([['source' => 'supplier', 'qty' => 3, 'warehouse_id' => 'a', 'warehouse' => 'A']], $p['segments']);
    }

    public function test_own_first_off_falls_back_to_the_shelf_when_the_supplier_is_short(): void
    {
        $p = ItemSourcing::plan(3, 2, [$this->wh('a', 1)], ['ownFirst' => false]);
        $this->assertSame(3, array_sum(array_column($p['segments'], 'qty')));
        $this->assertSame(0, $p['blocked']);
    }

    public function test_one_warehouse_that_covers_it_beats_splitting(): void
    {
        // first in order has 2, second has 5: the line goes to the second whole
        $p = ItemSourcing::plan(4, 0, [$this->wh('a', 2), $this->wh('b', 5)]);
        $this->assertSame([['source' => 'supplier', 'qty' => 4, 'warehouse_id' => 'b', 'warehouse' => 'B']], $p['segments']);
    }

    public function test_split_across_warehouses_when_none_covers_it(): void
    {
        $p = ItemSourcing::plan(5, 0, [$this->wh('a', 3), $this->wh('b', 3)]);
        $this->assertSame([3, 2], array_column($p['segments'], 'qty'));
        $this->assertSame(['a', 'b'], array_column($p['segments'], 'warehouse_id'));
    }

    public function test_nothing_anywhere_blocks_or_becomes_a_special_order(): void
    {
        $blocked = ItemSourcing::plan(2, 0, [], ['oversell' => false]);
        $this->assertSame([2, 0], [$blocked['blocked'], $blocked['special']]);
        $special = ItemSourcing::plan(2, 0, [], ['oversell' => true]);
        $this->assertSame([0, 2], [$special['blocked'], $special['special']]);
    }

    public function test_oversell_set_to_always_special_never_uses_a_supplier(): void
    {
        $p = ItemSourcing::plan(2, 0, [$this->wh('a', 9)], ['oversell' => true, 'oversellMode' => 'special']);
        $this->assertSame([], $p['segments']);
        $this->assertSame(2, $p['special']);
    }

    public function test_zero_stock_offers_are_ignored_and_headline_reads_right(): void
    {
        $p = ItemSourcing::plan(1, 0, [$this->wh('a', 0)], ['oversell' => true]);
        $this->assertSame('special', ItemSourcing::headline($p)['kind']);
        $s = ItemSourcing::plan(1, 0, [$this->wh('a', 4)]);
        $this->assertSame(['kind' => 'supplier', 'warehouse' => 'A'], ItemSourcing::headline($s));
        $this->assertSame('out', ItemSourcing::headline(ItemSourcing::plan(1, 0, []))['kind']);
    }

    public function test_quantities_always_add_up(): void
    {
        foreach ([[7, 3, 2, 2], [10, 0, 4, 3], [1, 1, 0, 0]] as [$want, $shop, $a, $b]) {
            foreach ([true, false] as $own) {
                $p = ItemSourcing::plan($want, $shop, [$this->wh('a', $a), $this->wh('b', $b)], ['ownFirst' => $own, 'oversell' => true]);
                $this->assertSame($want, array_sum(array_column($p['segments'], 'qty')) + $p['special']);
            }
        }
    }
}
