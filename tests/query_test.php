<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use GlpiPlugin\Reservaplus\{BookingQuery,BookingView};

rp_test('request discovery finds resources, places, borrower and ticket without requiring the booking id', function (): void {
    $booking=['id'=>42,'tickets_id'=>518,'purpose'=>'Oficina de capacitação'];
    $meta=['borrower'=>'João de Souza','resources'=>[
        ['name'=>'Notebook 07','kind'=>'equipment','location'=>'Edifício Central'],
        ['name'=>'Sala de reunião','kind'=>'room','location'=>'Bloco B / 2 / 204'],
    ]];
    foreach (['notebook','reuniao','EDIFICIO','Joao','518','#42','capacitacao'] as $q) {
        rp_assert(BookingQuery::matches($booking,$meta,['q'=>$q]),'Search should match '.$q);
    }
    rp_assert(!BookingQuery::matches($booking,$meta,['q'=>'Inexistente']));
});
rp_test('category filter matches any selected resource in a mixed booking', function (): void {
    $booking=['id'=>1,'tickets_id'=>0,'purpose'=>''];
    $meta=['borrower'=>'','resources'=>[['kind'=>'room'],['kind'=>'equipment']]];
    rp_assert(BookingQuery::matches($booking,$meta,['kind'=>'room']));
    rp_assert(BookingQuery::matches($booking,$meta,['kind'=>'equipment']));
    rp_assert(!BookingQuery::matches($booking,$meta,['kind'=>'other']));
});
rp_test('display dates use readable local day/month without changing invalid input', function (): void {
    rp_same('28/10/2026 14:30',BookingView::date('2026-10-28 14:30:00'));
    rp_same('-',BookingView::date(null));
    rp_same('2026-02-30 14:30:00',BookingView::date('2026-02-30 14:30:00'));
});
