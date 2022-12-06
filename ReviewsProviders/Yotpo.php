<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class Yotpo extends BaseReviewsProvider
{
    const PRODUCTS_PER_REQUEST = 100;

    protected $client;
    protected $utoken;
    protected $required_engine_data = [
        'yotpo_api_key',
        'yotpo_api_secret',
    ];

    public function __construct($engine_data)
    {
        parent::__construct($engine_data);

        $this->client = new \YotpoClient($engine_data['yotpo_api_key'], $engine_data['yotpo_api_secret']);

        if (defined('UNIT_TESTS_IN_PROGRESS')) {
            $credentials = (object)['access_token' => 'unit test'];
        } else {
            $credentials = $this->client->get_oauth_token();
        }

        if (!empty($credentials->access_token)) {
            $this->utoken = $credentials->access_token;
        } else {
            throw $this->makeException('Getting auth token failed');
        }
    }

    public function getAllProductReviewsTotals()
    {
        $page = 0;

        while (true) {
            $page += 1;

            while(true) {
                $response = $this->client->get_all_bottom_lines([
                    'utoken' => $this->utoken,
                    'count' => static::PRODUCTS_PER_REQUEST,
                    'page' => $page,
                ]);

                $this->debug(static::DEBUG_TYPE_REQUEST, $this->client->feed_url);
                $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

                if (empty($response->status) || $response->status->code != 200) {
                    $this->last_error = empty($response->status) ? 'Empty response status' : 'Response status is not 200 OK';

                    if ($this->proceedRetry($this->last_error, ['args' => [$response]])) {
                        continue;
                    }

                    break;
                }

                $this->last_error = null;
                $this->resetRetry();

                break;
            }

            if (!isset($response->response->bottomlines)) {
                $this->log('Response bottom lines are not set', $response);
                break;
            }

            $this->debug(static::DEBUG_TYPE_PRODUCTS, $response->response->bottomlines);
            $totals = [];

            foreach ($response->response->bottomlines as $bottom_line) {
                if (!empty($totals[$bottom_line->domain_key])) {
                    continue;
                }

                $totals[$bottom_line->domain_key] = [
                    'total_reviews' => $bottom_line->total_reviews,
                    'reviews_average_score' => $bottom_line->product_score,
                    'reviews_average_score_titles' => self::getAverageScoreTitle($bottom_line->product_score),
                ];
            }

            $this->totals_count += $this->saveTotals($totals);

            if ($this->isTesting() || $page > 1 && empty($response->response->bottomlines)) {
                break;
            }
        }

        return $this->totals_count;
    }

    public function getProductReviewsTotals($product_id)
    {
        $totals = $this->getEmptyTotals();

        try {
            $response = $this->client->get_product_bottom_line([
                'utoken' => $this->utoken,
                'product_id' => $product_id
            ]);
        } catch (\Exception $e) {
            $this->log($e->getMessage());

            return $totals;
        }

        $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

        if (!empty($response->status) && $response->status->code == 200) {
            $totals['total_reviews']                = $response->response->bottomline->total_reviews;
            $totals['reviews_average_score']        = $response->response->bottomline->average_score;
            $totals['reviews_average_score_titles'] = self::getAverageScoreTitle($response->response->bottomline->average_score);

        } elseif ($response->status->code != 404) {
            $this->log("Failed getting bottom line for product {$product_id}", \Yotpo\Yotpo::$app_key, $response);
        }

        return $totals;
    }
}
