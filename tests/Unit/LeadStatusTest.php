<?php

use Liberu\RealEstate\Leads\Domain\LeadStatus;

it('ranks lead statuses for automatic pipeline advancement', function (LeadStatus $status, int $rank) {
    expect($status->rank())->toBe($rank);
})->with([
    'new' => [LeadStatus::New, 0],
    'contacted' => [LeadStatus::Contacted, 1],
    'qualified' => [LeadStatus::Qualified, 2],
    'viewing' => [LeadStatus::Viewing, 3],
    'offer' => [LeadStatus::Offer, 4],
    'won' => [LeadStatus::Won, 5],
    'lost' => [LeadStatus::Lost, 5],
]);
