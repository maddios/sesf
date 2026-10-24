<?php

namespace App\Services\Registration;

enum LineItemKind: string
{
    case Car = 'car';
    case Guest = 'guest';
    case Shirt = 'shirt';
    case Extra = 'extra';
    case Donation = 'donation';
}
