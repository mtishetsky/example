<?php
/***************************************************************************
*                                                                          *
*    Copyright (c) 2004 Simbirsk Technologies Ltd. All rights reserved.    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/

use Core\ReviewsProviders\BaseReviewsProvider;
use Core\ReviewsProviders\DefaultReviewsProvider;

if (!defined('AREA') ) { die('Access denied'); }

class ReviewsProvider
{
    private static $default_review_provider = null;

    public static function getDefaultProvider()
    {
        if (self::$default_review_provider === null) {
            self::$default_review_provider = new DefaultReviewsProvider();
        }

        return self::$default_review_provider;
    }

    /**
     * @param  array  $engine_data
     * @param  bool  $check_required
     *
     * @return BaseReviewsProvider|null
     */
    public static function load(array $engine_data, bool $check_required = false): ?BaseReviewsProvider
    {
        if (empty($engine_data['reviews_provider'])) {
            throw new RuntimeException(self::makeErrorMessage(__METHOD__, 'Empty reviews_provider'));
        }

        $classname = self::getClassname($engine_data['reviews_provider']);

        if (!class_exists($classname)) {
            throw new RuntimeException(self::makeErrorMessage(__METHOD__, "Review class does not exist: {$classname}"));
        }

        $engine_data['bypass_required_data'] = empty($check_required);

        return new $classname($engine_data);
    }

    protected static function makeErrorMessage($method, $message)
    {
        return sprintf("In %s: %s", $method, $message);
    }

    public static function getClassname(string $providerName): string
    {
        return sprintf(
            "Core\\ReviewsProviders\\%s",
            implode('', array_map('ucfirst', explode('_', $providerName)))
        );
    }
}
