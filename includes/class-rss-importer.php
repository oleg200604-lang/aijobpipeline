<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RSS/Atom job importer.
 *
 * Converts RSS and Atom feed entries into the canonical
 * AIJP job structure.
 */
class AIJP_RSS_Importer
{
    /**
     * Import jobs from a source.
     *
     * @param object $source Source object.
     *
     * @return array
     */
    public function import(
        object $source
    ): array {
        if (empty($source->id)) {
            throw new RuntimeException(
                'RSS source ID is missing.'
            );
        }

        if (empty($source->url)) {
            throw new RuntimeException(
                'RSS source URL is missing.'
            );
        }

        $source_id = absint($source->id);

        $url = esc_url_raw(
            trim((string) $source->url)
        );

        if ($url === '') {
            throw new RuntimeException(
                'RSS source URL is invalid.'
            );
        }

        $response = wp_safe_remote_get(
            $url,
            [
                'timeout' => 30,

                'redirection' => 5,

                'headers' => [
                    'Accept' =>
                        'application/rss+xml, ' .
                        'application/atom+xml, ' .
                        'application/xml, ' .
                        'text/xml;q=0.9, ' .
                        '*/*;q=0.8',

                    'User-Agent' =>
                        'AI-Job-Pipeline/' .
                        AIJP_VERSION,
                ],
            ]
        );

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'RSS request failed: ' .
                $response->get_error_message()
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code(
            $response
        );

        if (
            $status_code < 200 ||
            $status_code >= 300
        ) {
            throw new RuntimeException(
                'RSS returned HTTP status ' .
                $status_code . '.'
            );
        }

        $body = wp_remote_retrieve_body(
            $response
        );

        if (trim($body) === '') {
            throw new RuntimeException(
                'RSS feed is empty.'
            );
        }

        /*
         * Some feeds contain a UTF-8 BOM.
         */
        $body = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $body
        );

        $previous_use_errors = libxml_use_internal_errors(
            true
        );

        $xml_flags = LIBXML_NOCDATA;

        if (defined('LIBXML_NONET')) {
            $xml_flags |= LIBXML_NONET;
        }

        $xml = simplexml_load_string(
            $body,
            'SimpleXMLElement',
            $xml_flags
        );

        $xml_errors = libxml_get_errors();

        libxml_clear_errors();

        libxml_use_internal_errors(
            $previous_use_errors
        );

        if ($xml === false) {
            $message = 'RSS XML is invalid.';

            if (!empty($xml_errors)) {
                $first_error = $xml_errors[0];

                if (
                    !empty($first_error->message)
                ) {
                    $message .= ' ' .
                        trim($first_error->message);
                }
            }

            throw new RuntimeException(
                $message
            );
        }

        $items = $this->get_items($xml);

        $result = [
            'found' => count($items),
            'added' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($items as $item) {
            try {
                $job = $this->normalize_item(
                    $item,
                    $source
                );

                /*
                 * A usable job requires at least a title and URL.
                 */
                if (
                    $job['title'] === '' ||
                    $job['url'] === ''
                ) {
                    $result['skipped']++;

                    continue;
                }

                if (
                    $job['external_id'] === ''
                ) {
                    $result['skipped']++;

                    continue;
                }

                $existing_job =
                    AIJP_Job_Repository::find_by_external_id(
                        $source_id,
                        $job['external_id']
                    );

                if ($existing_job) {
                    $result['duplicates']++;

                    continue;
                }

                $job_id =
                    AIJP_Job_Repository::insert_or_ignore(
                        $job
                    );

                if ($job_id === false) {
                    $result['failed']++;

                    continue;
                }

                $result['added']++;
            } catch (Throwable $e) {
                $result['failed']++;

                AIJP_Logger::error(
                    'RSS item import failed: ' .
                    $e->getMessage()
                );
            }
        }

        return $result;
    }

    /**
     * Extract items from RSS or Atom.
     *
     * @param SimpleXMLElement $xml XML document.
     *
     * @return array
     */
    private function get_items(
        SimpleXMLElement $xml
    ): array {
        /*
         * RSS 2.0:
         *
         * <rss>
         *   <channel>
         *     <item>
         */
        if (
            isset($xml->channel) &&
            isset($xml->channel->item)
        ) {
            return iterator_to_array(
                $xml->channel->item,
                false
            );
        }

        /*
         * RSS variants where item is directly accessible.
         */
        if (isset($xml->item)) {
            return iterator_to_array(
                $xml->item,
                false
            );
        }

        /*
         * Atom usually uses a default namespace:
         *
         * <feed xmlns="http://www.w3.org/2005/Atom">
         *   <entry>
         */
        $namespaces = $xml->getNamespaces(
            true
        );

        foreach ($namespaces as $prefix => $namespace) {
            $children = $xml->children(
                $namespace
            );

            if (
                isset($children->entry)
            ) {
                return iterator_to_array(
                    $children->entry,
                    false
                );
            }
        }

        /*
         * Fallback XPath for feeds where namespace handling differs.
         */
        $entries = $xml->xpath(
            '//*[local-name()="entry"]'
        );

        if (
            is_array($entries) &&
            !empty($entries)
        ) {
            return $entries;
        }

        $items = $xml->xpath(
            '//*[local-name()="item"]'
        );

        if (
            is_array($items) &&
            !empty($items)
        ) {
            return $items;
        }

        return [];
    }

    /**
     * Normalize one feed item.
     *
     * @param SimpleXMLElement $item Feed item.
     * @param object           $source Source.
     *
     * @return array
     */
    private function normalize_item(
        SimpleXMLElement $item,
        object $source
    ): array {
        $source_id = absint(
            $source->id
        );

        $title = $this->clean_text(
            $this->get_node_value(
                $item,
                'title'
            )
        );

        $description = $this->get_description(
            $item
        );

        $url = $this->get_item_url(
            $item
        );

        $external_id = $this->get_external_id(
            $item,
            $url,
            $source_id
        );

        $published_at =
            $this->get_published_at(
                $item
            );

        $language =
            $this->get_source_property(
                $source,
                [
                    'language',
                    'lang',
                ]
            );

        $country =
            $this->get_source_property(
                $source,
                [
                    'country',
                ]
            );

        $category =
            $this->get_category(
                $item
            );

        return [
            'source_id' => $source_id,

            'external_id' => $external_id,

            'title' => $title,

            'description' => $description,

            'url' => $url,

            'budget_amount' => null,

            'budget_currency' => null,

            'lang' => $language,

            'country' => $country,

            'category' => $category,

            'published_at' => $published_at,

            'found_at' => current_time(
                'mysql',
                true
            ),

            'status' =>
                AIJP_Job_Status::FOUND,

            'assignee' => null,

            'hash' =>
                AIJP_Job_Repository::build_hash(
                    $source_id,
                    $external_id
                ),
        ];
    }

    /**
     * Get item description.
     *
     * @param SimpleXMLElement $item Feed item.
     *
     * @return string
     */
    private function get_description(
        SimpleXMLElement $item
    ): string {
        $namespaces = $item->getNamespaces(
            true
        );

        /*
         * WordPress content:encoded.
         */
        if (
            isset($namespaces['content'])
        ) {
            $content = $item->children(
                $namespaces['content']
            );

            if (
                isset($content->encoded)
            ) {
                $value = trim(
                    (string) $content->encoded
                );

                if ($value !== '') {
                    return $this->clean_description(
                        $value
                    );
                }
            }
        }

        /*
         * Atom and RSS direct fields.
         */
        foreach (
            [
                'description',
                'summary',
                'content',
            ] as $field
        ) {
            $value = $this->get_node_value(
                $item,
                $field
            );

            if ($value !== '') {
                return $this->clean_description(
                    $value
                );
            }
        }

        /*
         * Namespace-independent fallback.
         */
        foreach (
            [
                'content',
                'summary',
                'description',
            ] as $field
        ) {
            $nodes = $item->xpath(
                './*[local-name()="' .
                $field .
                '"]'
            );

            if (
                is_array($nodes) &&
                !empty($nodes)
            ) {
                $value = trim(
                    (string) $nodes[0]
                );

                if ($value !== '') {
                    return $this->clean_description(
                        $value
                    );
                }
            }
        }

        return '';
    }

    /**
     * Get item URL.
     *
     * Supports:
     *
     * RSS:
     * <link>https://example.com/job</link>
     *
     * Atom:
     * <link href="https://example.com/job"/>
     *
     * Atom alternate:
     * <link rel="alternate" href="..."/>
     *
     * @param SimpleXMLElement $item Feed item.
     *
     * @return string
     */
    private function get_item_url(
        SimpleXMLElement $item
    ): string {
        $links = $item->xpath(
            './*[local-name()="link"]'
        );

        if (
            is_array($links) &&
            !empty($links)
        ) {
            /*
             * Prefer rel="alternate".
             */
            foreach ($links as $link) {
                $attributes = $link->attributes();

                $rel = isset($attributes['rel'])
                    ? strtolower(
                        trim(
                            (string) $attributes['rel']
                        )
                    )
                    : '';

                $href = isset($attributes['href'])
                    ? trim(
                        (string) $attributes['href']
                    )
                    : '';

                if (
                    $href !== '' &&
                    (
                        $rel === 'alternate' ||
                        $rel === ''
                    )
                ) {
                    $url = esc_url_raw(
                        $href
                    );

                    if ($url !== '') {
                        return $url;
                    }
                }
            }

            /*
             * Any valid href.
             */
            foreach ($links as $link) {
                $attributes = $link->attributes();

                if (
                    isset($attributes['href'])
                ) {
                    $url = esc_url_raw(
                        trim(
                            (string) $attributes['href']
                        )
                    );

                    if ($url !== '') {
                        return $url;
                    }
                }
            }

            /*
             * RSS text link.
             */
            foreach ($links as $link) {
                $url = esc_url_raw(
                    trim(
                        (string) $link
                    )
                );

                if ($url !== '') {
                    return $url;
                }
            }
        }

        /*
         * GUID fallback.
         */
        $guid = $this->get_node_value(
            $item,
            'guid'
        );

        if ($guid !== '') {
            $url = esc_url_raw(
                $guid
            );

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * Get deterministic external ID.
     *
     * @param SimpleXMLElement $item Feed item.
     * @param string           $url Item URL.
     * @param int              $source_id Source ID.
     *
     * @return string
     */
    private function get_external_id(
        SimpleXMLElement $item,
        string $url,
        int $source_id
    ): string {
        foreach (
            [
                'guid',
                'id',
            ] as $field
        ) {
            $value = $this->get_node_value(
                $item,
                $field
            );

            if ($value !== '') {
                return $value;
            }
        }

        if ($url !== '') {
            return sha1(
                $source_id .
                '|' .
                $url
            );
        }

        $fallback = implode(
            '|',
            [
                $source_id,

                $this->get_node_value(
                    $item,
                    'title'
                ),

                $this->get_node_value(
                    $item,
                    'pubDate'
                ),

                $this->get_node_value(
                    $item,
                    'published'
                ),
            ]
        );

        return sha1($fallback);
    }

    /**
     * Get publication date.
     *
     * @param SimpleXMLElement $item Feed item.
     *
     * @return string|null
     */
    private function get_published_at(
        SimpleXMLElement $item
    ): ?string {
        foreach (
            [
                'pubDate',
                'published',
                'updated',
                'date',
            ] as $field
        ) {
            $value = $this->get_node_value(
                $item,
                $field
            );

            if ($value === '') {
                continue;
            }

            $timestamp = strtotime(
                $value
            );

            if ($timestamp !== false) {
                return gmdate(
                    'Y-m-d H:i:s',
                    $timestamp
                );
            }
        }

        return null;
    }

    /**
     * Get categories.
     *
     * @param SimpleXMLElement $item Feed item.
     *
     * @return string|null
     */
    private function get_category(
        SimpleXMLElement $item
    ): ?string {
        $nodes = $item->xpath(
            './*[local-name()="category"]'
        );

        if (
            !is_array($nodes) ||
            empty($nodes)
        ) {
            return null;
        }

        $categories = [];

        foreach ($nodes as $category) {
            $attributes =
                $category->attributes();

            $value = '';

            if (
                isset($attributes['term'])
            ) {
                $value = trim(
                    (string) $attributes['term']
                );
            }

            if ($value === '') {
                $value = trim(
                    (string) $category
                );
            }

            if ($value !== '') {
                $categories[] =
                    $this->clean_text(
                        $value
                    );
            }
        }

        $categories = array_unique(
            $categories
        );

        if (empty($categories)) {
            return null;
        }

        return substr(
            implode(
                ', ',
                $categories
            ),
            0,
            50
        );
    }

    /**
     * Get a direct or namespace-independent node value.
     *
     * @param SimpleXMLElement $item Item.
     * @param string           $name Node name.
     *
     * @return string
     */
    private function get_node_value(
        SimpleXMLElement $item,
        string $name
    ): string {
        if (
            isset($item->{$name})
        ) {
            $value = trim(
                (string) $item->{$name}
            );

            if ($value !== '') {
                return $value;
            }
        }

        $nodes = $item->xpath(
            './*[local-name()="' .
            $name .
            '"]'
        );

        if (
            is_array($nodes) &&
            !empty($nodes)
        ) {
            return trim(
                (string) $nodes[0]
            );
        }

        return '';
    }

    /**
     * Get source property.
     *
     * @param object $source Source.
     * @param array  $names Property names.
     *
     * @return string|null
     */
    private function get_source_property(
        object $source,
        array $names
    ): ?string {
        foreach ($names as $name) {
            if (
                !isset($source->{$name})
            ) {
                continue;
            }

            $value = trim(
                (string) $source->{$name}
            );

            if ($value !== '') {
                return sanitize_text_field(
                    $value
                );
            }
        }

        return null;
    }

    /**
     * Clean ordinary text.
     *
     * @param string $text Text.
     *
     * @return string
     */
    private function clean_text(
        string $text
    ): string {
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = wp_strip_all_tags(
            $text
        );

        $text = preg_replace(
            '/\s+/u',
            ' ',
            $text
        );

        return trim(
            $text
        );
    }

    /**
     * Clean feed description.
     *
     * @param string $description Description.
     *
     * @return string
     */
    private function clean_description(
        string $description
    ): string {
        $description = html_entity_decode(
            $description,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $description = wp_strip_all_tags(
            $description
        );

        $description = preg_replace(
            '/\s+/u',
            ' ',
            $description
        );

        return trim(
            $description
        );
    }
}
