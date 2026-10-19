<?php

namespace App\Services\Push;

enum FcmDelivery
{
    case Sent;
    case Stale;
    case Failed;
}
