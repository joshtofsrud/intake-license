<?php
// MARKER-BRAND-LIST — each industry gets its own "Brands to look for" list; the
// bike list is filled in. Owner names the old pattern got wrong ("here every
// single") are cleared, and every shop the website pass has read is read again
// so brands and owners come from the new rules.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const BIKE_BRANDS = <<<'TXT'
Trek
Specialized
Giant
Liv
Cannondale
Santa Cruz: Santa Cruz Bicycles
Juliana
Yeti: Yeti Cycles
Pivot: Pivot Cycles
Ibis
Rocky Mountain: Rocky Mountain Bicycles
Norco
Kona
Transition: Transition Bikes
Evil: Evil Bikes
Revel: Revel Bikes
Intense: Intense Cycles
Niner
Salsa: Salsa Cycles
Surly
All-City
Marin: Marin Bikes
Scott: Scott Bikes, Scott Sports
Orbea
Cervélo: Cervelo
BMC
Pinarello
Bianchi
Colnago
Felt
Fuji
Diamondback
GT: GT Bicycles
Mongoose
Haro
Redline
Raleigh
Jamis
Breezer
Masi
Co-Motion
Moots
Seven Cycles
Allied: Allied Cycle Works
OPEN Cycle: OPEN U.P.
3T
Factor: Factor Bikes
Canyon
YT: YT Industries
Commencal
Nukeproof
Banshee
Knolly
Chromag
Devinci
Guerrilla Gravity
Forbidden: Forbidden Bike Co
Mondraker
Lapierre
Merida
Cube
Ridley
Wilier
Argon 18
Lynskey
Litespeed
Ritchey
Soma: Soma Fabrications
Rivendell
Crust: Crust Bikes
Fairdale
State Bicycle
Esker
Why Cycles
Otso
Fezzari
Alchemy: Alchemy Bicycles
Rodeo Labs
Ventana
Spot Brand
Turner: Turner Bikes
Pole: Pole Bicycles
Electra
Linus
Priority Bicycles
Tern
Brompton
Benno
Yuba
Xtracycle
Riese & Müller: Riese and Muller, Riese & Muller
Gazelle
Aventon
Rad Power Bikes: Rad Power, RadPower
Pedego
Super73
Lectric
Ride1Up
Velotric
Gocycle
Strider
Woom
Prevelo
Frog Bikes
Spawn Cycles
Early Rider
Cleary
SE Bikes
WeThePeople
Kink
TXT;

    public function up(): void
    {
        if (! Schema::hasColumn('sales_channels', 'brand_list')) {
            Schema::table('sales_channels', fn (Blueprint $t) => $t->text('brand_list')->nullable());
        }
        DB::table('sales_channels')
            ->where(fn ($q) => $q->where('slug', 'bike-shops')->orWhere('name', 'Bike shops'))
            ->where(fn ($q) => $q->whereNull('brand_list')->orWhere('brand_list', ''))
            ->update(['brand_list' => self::BIKE_BRANDS]);

        if (! Schema::hasColumn('sales_prospects', 'site_scanned_at')) return;

        // owners the website pass filled that aren't capitalised names
        $ids = DB::table('sales_activities')->where('type', 'system')
            ->where('body', 'like', 'Website pass found%owner%')->pluck('sales_prospect_id')->unique();
        foreach ($ids->chunk(500) as $chunk) {
            foreach (DB::table('sales_prospects')->whereIn('id', $chunk)->whereNotNull('owner_contact')->get(['id', 'owner_contact']) as $p) {
                $words = preg_split('/\s+/', trim((string) $p->owner_contact));
                $ok = count($words) >= 2 && ! array_filter($words, fn ($w) => ! preg_match('/^\p{Lu}/u', $w));
                if (! $ok) DB::table('sales_prospects')->where('id', $p->id)->update(['owner_contact' => null]);
            }
        }

        // read again with the new rules; the scheduler picks these up like unread shops
        DB::table('sales_prospects')->whereNotNull('site_scanned_at')
            ->whereIn('site_scan_status', ['ok', 'nothing_found'])
            ->update(['site_scanned_at' => null]);
    }

    public function down(): void {}
};
