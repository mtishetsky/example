<?php

namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class DefaultReviewsProvider extends BaseReviewsProvider
{
    const TABLE_DEF = null;

    public function getAllProductReviewsTotals()
    {
        return [];
    }
}
