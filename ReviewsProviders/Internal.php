<?php
namespace Core\ReviewsProviders;

use Core\Css\ProductProperties;

class Internal extends BaseReviewsProvider
{
    const TABLE_DEF = null;

    protected static $skip_hash_check = true;

    public function getAllProductReviewsTotals()
    {
        if (!fn_widgets_is_reviews_available($this->getEngineData())) {
            return [];
        }

        $has_reviews = (int)db_get_field("user#SELECT product_id FROM ?:summary WHERE reviews_average_score_titles <> 'nostar' LIMIT 1");
        $widget_settings = fn_get_widget_settings($this->getEngineData('engine_id'));

        if ($has_reviews > 0) {
            foreach (ProductProperties::getReviewsProperties() as $review_property) {
                fn_cs_property_update($review_property, null, true);
            }

            if ($widget_settings['ReviewsShowRating'] != 'Y') {
                fn_update_widget_settings(array('ReviewsShowRating' => 'Y'), $this->getEngineData('engine_id'), true, false);
            }

        } else {
            if ($widget_settings['ReviewsShowRating'] != 'N') {
                fn_update_widget_settings(array('ReviewsShowRating' => 'N'), $this->getEngineData('engine_id'), true, false);
            }
        }

        return [];
    }
}
