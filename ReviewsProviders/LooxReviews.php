<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class LooxReviews extends BaseReviewsProvider
{
    const ERROR_EMPTY_RESPONSE = 'Empty response from API';
    const ERROR_UNAVAILABLE_SHOP = 'Unavailable Shop';

    protected $required_engine_data = [
        'shopify_access_token'
    ];
    protected $fatal_errors = [
        'Unavailable Shop' => 1,
        'Invalid API key or access token' => 1,
    ];

    protected function getProductsSummary($product_ids)
    {
        $product_ids = is_array($product_ids) ? $product_ids : [$product_ids];

        $request = \Core\GraphQL\ShopifyGraphQL::getInstance()
            ->setSession($this->getEngineData('name'), $this->getEngineData('shopify_access_token'))
            ->initQuery()
            ->addProductsQuery($product_ids, [
                'id',
                'avg_rating' => [
                    'metafield',
                    'value',
                    'namespace' => '"loox"',
                    'key' => '"avg_rating"',
                ],
                'num_reviews' => [
                    'metafield',
                    'value',
                    'namespace' => '"loox"',
                    'key' => '"num_reviews"',
                ],
            ]);

        while (1) {
            $response = $request->execute(\Core\GraphQL\ShopifyGraphQL::ROOT_NODES);

            $this->debug(static::DEBUG_TYPE_REQUEST, $this->getGraphqlRequestDebug($request));
            $this->debug(static::DEBUG_TYPE_RESPONSE, print_r($response,1));

            if (empty($response) || !empty($response['error'])) {
                $this->last_error = empty($response) ? static::ERROR_EMPTY_RESPONSE : $response['error'];

                if ($this->proceedRetry($this->last_error)) {
                    continue;
                }

                return [];
            }

            $this->last_error = null;
            $this->resetRetry();

            break;
        }

        $this->debug(static::DEBUG_TYPE_PRODUCTS, $response);

        $totals = [];

        foreach ($response as $product_data) {
            $this->debug(static::DEBUG_TYPE_PRODUCT, $product_data);

            if (empty($product_data['avg_rating']) || empty($product_data['num_reviews'])) {
                continue;
            }

            if (preg_match('/^gid:\/\/shopify\/Product\/(\d+)$/', $product_data['id'], $matches)) {
                $totals[$matches[1]] = [
                    'total_reviews' => (int)$product_data['num_reviews']['value'],
                    'reviews_average_score' => (float)$product_data['avg_rating']['value'],
                    'reviews_average_score_titles' => self::getAverageScoreTitle((float)$product_data['avg_rating']['value']),
                ];
            }
        }

        return $totals;
    }
}
