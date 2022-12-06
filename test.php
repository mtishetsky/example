<?php

restore_error_handler();
error_reporting(E_ALL);
ini_set('display_errors', true);

if (!defined('AREA') || !defined('CONSOLE')) {
    die('Access denied');
}

if ($mode == 'providers') {
    $options_bindings = [
        'engine_id' => 'e|engine:<number>[,<number>]:Test only engines with specified engine_id. Accepts multiple values, comma-separated.',
        'provider' => 'p|provider:<string>[,<string>]:Test randomly selected engines with specified provider. Accepts multiple values, comma-separated.\nBy default only 1 engine per provider is selected, use --limit to change this.',
        'product' => 'r|product:<string>:Test only specified single product, --engine is required',
        'db_host' => 'h|host:<string>:Override database host',
        'debug'  => 'd|debug:ALL|<string>[,<string>]:Display debug data for each http request. Accepts multiple values, comma-separated.\nAllowed values are request, response, headers, products, product, ALL',
        'limit'  => 'l|limit:<number>:Randomly select specified number of engines for each provider',
        'logger' => 'g|logger:<callable>:Override default logger, must be valid php callable',
        'count' => 'c|count:<max>|<min>,<max>:Limit randomly selected engines to those having number of products less than max or between min and max, exclusive (<, not <=)',
        'noreviews' => 'n|noreviews:1:Skip retrieving reviews text for engines that support search by reviews text and have it enabled',
        'testing' => 't|testing:1:For providers that perform multiple http requests only perform the first request',
        'reviews' => 'w|reviews:1:Limit randomly selected engines to only having search by reviews text enabled',
        'json' => 'j|json:1:Output everything as json',
        'core' => 'o|core:1:Use reviews table in core db instead of engine db',
        'update' => 'u|update:1:Update reviews summary after all (not available with --core)',
        'force_update' => 'f|force:1:Update reviews summary even if hash already matches (requires --update, not available with --core)',
        'only_update' => 'only_update:1:Update reviews summary from current data, do not fetch anything',
        'function' => 'i|function:1:Call fn_fetch_product_reviews() on given engines',
    ];

    foreach ($GLOBALS['argv'] as $arg) {
        if ($arg == '--help') {
            ProvidersTester::showHelp($options_bindings);
            exit;
        }
    }

    $values = ProvidersTester::parseOptions($options_bindings);

    if (isset($values['engine_id'])) {
        $engine_from_options = true;
        $engines = explode(',', $values['engine_id']);
    }

    if (isset($values['provider'])) {
        $provider_from_options = true;
        $providers = explode(',', $values['provider']);
    }

    if (isset($values['limit'])) {
        $limit = $values['limit'];
        $limit_from_options = true;
    }

    $sql = 'SELECT DISTINCT reviews_provider FROM ?:engines_extra WHERE reviews_provider ' . (isset($providers) ? 'IN (?a)' : '!= ""');
    $sql .= ' AND reviews_provider != "internal"';
    if (isset($values['reviews'])) {
        $sql .= ' AND reviews_is_search_by_reviews_enabled = "Y"';
    }
    $_providers = db_get_fields($sql, isset($providers) ? $providers : '');

    if (!empty($providers) && empty($_providers)) {
        $providers = join(', ', $providers);
        exit("Invalid providers: {$providers}\n");
    }

    $providers = $_providers;
    sort($providers);

    if (!isset($values['engine_id']) && !isset($values['provider'])) {
        ProvidersTester::testFailures($providers);
    }

    if (empty($engines)) {
        $engines = [];

        foreach ($providers as $p) {
            if (empty($p)) {
                continue;
            }

            $condition = "status = 'A' AND reviews_provider = '{$p}'";
            if (!empty($values['reviews'])) {
                $condition .= ' AND reviews_is_search_by_reviews_enabled = "Y"';
            }

            if (!empty($values['count'])) {
                $count = explode(',', $values['count']);
                if (count($count) == 1 && intval($count[0]) > 0) {
                    $condition .= ' AND e.products_count < ' . intval($count[0]);
                } elseif (count($count) == 2) {
                    $count = array_map(function($x) {
                        return intval($x);
                    }, $count);

                    if ($count[1] > 0) {
                        $condition .= ' AND e.products_count BETWEEN ' . join(' AND ', $count);
                    }
                }
            }

            $_limit = isset($limit) ? $limit : 1;
            $_engines = db_get_fields("
                SELECT
                    ee.engine_id
                FROM
                    ?:engines_extra ee
                JOIN
                    ?:engines e
                ON
                    e.engine_id = ee.engine_id
                WHERE
                    {$condition}
                ORDER BY RAND()
                LIMIT ?i
                ", $_limit
            );

            if (empty($_engines)) {
                printf("No engines for provider %s, exit\n", $p);
                exit;
            }

            $engines = array_merge($engines, $_engines);
        }
    }

    foreach ($engines as $engine_id) {
        if (empty($engine_id)) {
            continue;
        }

        $engine_data = fn_get_engine_full_data($engine_id);

        if (empty($engine_data)) {
            printf("No data for engine %s!\n", $engine_id);
            continue;
        }

        if (!empty($values['db_host'])) {
            $engine_data['db_host'] = $values['db_host'];
        }

        if (!empty($values['function'])) {
            Registry::set('runtime.engine_data', $engine_data);
            $res = fn_fetch_product_reviews($engine_data);
            printf("fn_fetch_product_reviews for %s %sUPDATED\n", $engine_data['engine_id'], $res ? '' : 'NOT ');
            continue;
        }

        $result = [
            'engine_id' => $engine_id,
            'provider' => $engine_data['reviews_provider'],
            'products_count' => (int)$engine_data['products_count'],
        ];

        if (empty($values['json'])) {
            $format = "Testing %s with engine %s, has %s products\n";
            printf($format, $result['provider'], $result['engine_id'], $result['products_count']);
        }

        try {
            $provider = ReviewsProvider::load($engine_data, true);

            $logger = isset($values['logger']) && is_callable($values['logger'])
                ? $values['logger']
                : function ($z) use (& $result, $values) {
                    empty($values['json'])
                        ? print print_r($z, 1) . "\n"
                        : $result['errors'][] = $z;
                };

            $provider->setLogger($logger);

            if (isset($values['testing'])) {
                $provider->setTesting($values['testing']);
            }

            if (isset($values['debug'])) {
                $provider->setDebug($values['debug']);
            }

            if (!$provider->testConnect()) {
                $provider->log('Failed testConnect!');
            }

            empty($values['core']) ? fn_cs_switch_db($engine_data) : $provider::setDbPrefix('');
            $provider::createTable();



            if (!isset($values['product']) && empty($values['only_update'])) {
                $started = microtime(true);
                $totals_count = $provider->getAllProductReviewsTotals();
                $new_hash = $provider::getTotalsHash();
                $text = sprintf(
                    "Got %s totals in %.2fs, %s",
                    $totals_count,
                    microtime(1) - $started,
                    empty($new_hash) ? 'NO HASH' : 'hash ' . $new_hash . ($new_hash == $engine_data['reviews_hash'] ? ' matches' : ' != ' . $engine_data['reviews_hash'])
                );
                $result = array_merge($result, [
                    'totals_count' => $totals_count,
                ]);
            } else {
                $text = 'Skip totals';
            }

            if ($engine_data['reviews_is_search_by_reviews_enabled'] == 'Y' && !isset($values['noreviews']) && empty($values['only_update'])) {
                $started = microtime(true);
                $reviews_count = $provider->getAllProductReviews();
                $text .= sprintf(", Got %s reviews in %.2fs", $reviews_count, microtime(1) - $started);
                $result = array_merge($result, [
                    'reviews_count' => $reviews_count,
                ]);
            }

            if (isset($values['product']) && empty($values['only_update'])) {
                $item_id = $values['product'];

                $started = microtime(true);
                $single = $provider->getProductReviewsTotals($item_id);
                $result = array_merge($result, [
                    'single_item' => array_merge([
                        'id' => $item_id,
                    ], $single),
                ]);
                $single = array_filter($single, function ($x) {
                    return is_scalar($x);
                });
                $single = array_merge(['id' => $item_id], $single);
                $text .= sprintf(", Got single: %s in %.2fs", join(" : ", $single), microtime(1) - $started);
            }

            if (empty($values['core']) && !empty($values['update'])) {
                $res = $provider->updateSummaryReviewsTotals(!empty($values['force_update']));
                $text .= "\n" . $provider->getLastMessage();
            }

            print(empty($values['json']) ? "$text\n\n" : json_encode($result) . "\n");

        } catch (\Exception $e) {
            print "     ERROR: " . $e->getMessage() . "\n";
            continue;
        }
    }

    exit;
}

class ProvidersTester
{
    static function testFailures($providers)
    {
        static::title('Test failures handling');

        try {
            static::loadEmptyProvider([]);
        } catch (\Exception $e) {
            print $e->getMessage() . "\n";
        }

        try {
            static::loadInvalidProvider(['reviews_provider' => 'zzz']);
        } catch (\Exception $e) {
            print $e->getMessage() . "\n";
        }

        foreach ($providers as $p) {
            $engine_id = db_get_field('SELECT engine_id FROM ?:engines_extra WHERE reviews_provider = ?s ORDER BY RAND() LIMIT 1', $p);
            $engine_data = fn_get_engine_full_data($engine_id);

            try {
                static::loadInvalidRequired($engine_data);
            } catch (\Exception $e) {
                printf("%s\n", $e->getMessage());
            }
        }

        static::title('Test normal operation');
    }

    static function title($s)
    {
        print "\n---------- $s ----------\n";
    }

    static function loadEmptyProvider($engine_data)
    {
        unset($engine_data['reviews_provider']);
        ReviewsProvider::load($engine_data, true);
    }

    static function loadInvalidProvider($engine_data)
    {
        $engine_data['reviews_provider'] .= 'zz';
        ReviewsProvider::load($engine_data, true);
    }

    static function loadInvalidRequired($engine_data)
    {
        $provider = ReviewsProvider::load($engine_data);

        foreach ($provider->getRequiredEngineData() as $k) {
            unset($engine_data[$k]);
            return ReviewsProvider::load($engine_data, true);
        }
    }

    static function parseOptions($options_bindings)
    {
        $long = $short = $map = $values = [];

        foreach ($options_bindings as $var => $options) {
            $type = ':';

            $options = explode(':', $options);
            $options = reset($options);

            foreach (explode('|', $options) as $o) {
                strlen($o) == 1 ? $short[] = $o . $type : $long[] = $o . $type;
                $map[$o] = $var;
            }
        }

        $options = getopt(join('', $short), $long);

        foreach ($map as $option => $var) {
            if (isset($options[$option])) {
                $values[$var] = $options[$option];
            }
        }

        return $values;
    }

    static function showHelp($options_bindings)
    {
        echo "\nReview providers tester usage:\n\n";

        $help = [];
        $max_len = 0;

        foreach ($options_bindings as $var => $options) {
            $keys = [];

            list($options, $types, $descr) = explode(':', $options);

            foreach (explode('|', $options) as $o) {
                strlen($o) == 1 ? $keys[] = '-' . $o : $keys[] = '--' . $o;
            }

            $help[$var] = [
                'descr' => $descr,
                'keys' => implode(', ', $keys) . ' '. $types,
            ];

            if (strlen($help[$var]['keys']) > $max_len) {
                $max_len = strlen($help[$var]['keys']);
            }
        }

        foreach ($help as $data) {
            printf(
                "%s %s\n",
                str_pad($data['keys'], $max_len, ' '),
                str_replace('\n', "\n".str_repeat(' ', $max_len + 1), $data['descr'])
            );
        }
    }
}
