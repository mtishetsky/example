<?php
namespace Core\ReviewsProviders;

if (!defined('AREA') ) { die('Access denied'); }

class ProductReviews extends BaseReviewsProvider
{
    protected $required_engine_data = [
        'shopify_access_token'
    ];
    protected $fatal_errors = [
        'Unavailable Shop' => 1,
        'This store is unavailable' => 1,
    ];

    protected function getProductsSummary($product_ids)
    {
        $totals = [];
        $product_ids = is_array($product_ids) ? $product_ids : [$product_ids];

        $request = \Core\GraphQL\ShopifyGraphQL::getInstance()
            ->setSession($this->getEngineData('name'), $this->getEngineData('shopify_access_token'))
            ->initQuery()
            ->addProductsQuery($product_ids, [
                'id',
                [
                    'metafield',
                    'value',
                    'namespace' => '"spr"',
                    'key' => '"reviews"',
                ],
            ]);

        while (1) {
            $response = $request->execute(\Core\GraphQL\ShopifyGraphQL::ROOT_NODES);

            $this->debug(static::DEBUG_TYPE_REQUEST, $this->getGraphqlRequestDebug($request));
            $this->debug(static::DEBUG_TYPE_RESPONSE, $response);

            if (empty($response) || !empty($response['error'])) {
                $this->last_error = empty($response) ? self::ERROR_EMPTY_SUMMARY_RESPONSE : $response['error'];

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

        foreach ($response as $product_data) {
            if (empty($product_data['metafield']['value'])) {
                continue;
            }

            $this->debug(static::DEBUG_TYPE_PRODUCT, $product_data);

            if (preg_match('/^gid:\/\/shopify\/Product\/(\d+)$/', $product_data['id'], $matches)) {
                $reviews_count = $this->getReviewsCount($product_data['metafield']);

                if ($reviews_count == 0) {
                    continue;
                }

                $rating_value = $this->getRatingValue($product_data['metafield']);

                $totals[$matches[1]] = array(
                    'total_reviews' => $reviews_count,
                    'reviews_average_score' => $rating_value,
                    'reviews_average_score_titles' => self::getAverageScoreTitle($rating_value),
                );
            }
        }

        return $totals;
    }

    protected function getReviewsCount($metafield)
    {
        return $this->getValueFromMetafield('reviews_count', $metafield);
    }

    protected function getRatingValue($metafield)
    {
        return $this->getValueFromMetafield('rating_value', $metafield);
    }

    protected function getValueFromMetafield($key, $metafield)
    {
        $patterns['reviews_count'] = [
            '/<meta\s+itemprop="reviewCount"\s+content="(\d+)">/u',
            '/"reviewCount":\s+"(\d+)"/u',
        ];

        $patterns['rating_value'] = [
            '/<meta\s+itemprop="ratingValue"\s+content="(.*)">/u',
            '/"ratingValue":\s+"(.*)"/u',
        ];

        foreach ($patterns[$key] as $p) {
            if (preg_match($p, $metafield['value'], $reviews_match)) {
                return $reviews_match[1];
            }
        }

        return 0;
    }
}
