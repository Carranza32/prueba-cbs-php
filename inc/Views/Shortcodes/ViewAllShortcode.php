<?php

namespace CBSNorthStar\Views\Shortcodes;

/**
 * ViewAllShortcode - Displays "View All" with product count for a category
 *
 */
class ViewAllShortcode
{
    /**
     * Render the shortcode output
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render(array $atts = []): string
    {
        $atts = shortcode_atts(
            [
                'category' => '',
            ],
            $atts,
            'view_all_category'
        );

        $category_slug = sanitize_title($atts['category']);

        if (empty($category_slug)) {
            return '';
        }

        $category = get_term_by('slug', $category_slug, 'product_cat');

        if (!$category || is_wp_error($category)) {
            return '';
        }

        $product_count = $this->getProductCount($category->term_id);

        $output = $this->buildOutput($category, $product_count);

        return $output;
    }

    /**
     * Get the count of published products in a category
     *
     * @param int $term_id Category term ID
     * @return int Product count
     */
    private function getProductCount(int $term_id): int
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $term_id,
                    'include_children' => false,
                ],
            ],
        ];

        $query = new \WP_Query($args);

        return $query->found_posts;
    }

    /**
     * Build the HTML output
     *
     * @param \WP_Term $category Category term object
     * @param int $count Product count
     * @return string HTML output
     */
    private function buildOutput(\WP_Term $category, int $count): string
    {
        $url = add_query_arg(
            [
                'cat_slug' => $category->slug,
                'cat_name' => $category->name,
            ],
            home_url('/menu-items/')
        );

        ob_start();
        ?>
        <div class="view-all-category">
            <a href="<?php echo esc_url($url); ?>" class="view-all-link">
                <span class="view-all-text"><?php echo esc_html__('View All', 'northstaronlineordering'); ?></span>
                <span class="view-all-count">(<?php echo absint($count); ?>)</span>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}
