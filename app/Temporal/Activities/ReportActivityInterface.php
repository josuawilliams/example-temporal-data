<?php

namespace App\Temporal\Activities;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Report.')]
interface ReportActivityInterface
{
    #[ActivityMethod]
    public function fetchAkta(int $limit): array;

    #[ActivityMethod]
    public function fetchAngsuran(int $limit): array;

    #[ActivityMethod]
    public function fetchDebitNote(int $limit): array;
}
