<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates importing jobs from all enabled sources.
 */
class AIJP_Job_Importer
{
    /**
     * Import all enabled RSS sources.
     *
     * @return array
     */
    public function import_enabled_sources(): array
    {
        $sources = AIJP_Source_Repository::get_enabled_by_type('RSS');

        $summary = [
            'sources'   => count($sources),
            'found'     => 0,
            'added'     => 0,
            'duplicates'=> 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => 0,
        ];

        foreach ($sources as $source) {
            $result = $this->import_source($source);

            $summary['found'] += isset($result['found'])
                ? (int) $result['found']
                : 0;

            $summary['added'] += isset($result['added'])
                ? (int) $result['added']
                : 0;

            $summary['duplicates'] += isset($result['duplicates'])
                ? (int) $result['duplicates']
                : 0;

            $summary['skipped'] += isset($result['skipped'])
                ? (int) $result['skipped']
                : 0;

            $summary['failed'] += isset($result['failed'])
                ? (int) $result['failed']
                : 0;

            /*
             * "errors" means source-level failure.
             * Item-level failures are counted separately in "failed".
             */
            $summary['errors'] += isset($result['errors'])
                ? (int) $result['errors']
                : 0;
        }

        return $summary;
    }

    /**
     * Import one source.
     *
     * @param object $source Source object.
     * @return array
     */
    public function import_source(object $source): array
    {
        $result = [
            'found'      => 0,
            'added'      => 0,
            'duplicates' => 0,
            'skipped'    => 0,
            'failed'     => 0,
            'errors'     => 0,
        ];

        /*
         * Validate source before doing any network request.
         */
        if (empty($source->id)) {
            $result['errors'] = 1;

            AIJP_Logger::error(
                'RSS import skipped: source ID is missing.'
            );

            return $result;
        }

        $source_id = absint($source->id);

        $source_name = !empty($source->name)
            ? sanitize_text_field((string) $source->name)
            : 'Source #' . $source_id;

        $source_type = isset($source->source_type)
            ? strtoupper(trim((string) $source->source_type))
            : '';

        $enabled = !empty($source->enabled);

        if (!$enabled) {
            $result['errors'] = 1;

            AIJP_Logger::warning(
                sprintf(
                    'Source "%s" was skipped because it is disabled.',
                    $source_name
                )
            );

            return $result;
        }

        if ($source_type !== 'RSS') {
            $result['errors'] = 1;

            AIJP_Logger::warning(
                sprintf(
                    'Source "%s" was skipped because its type is "%s", not RSS.',
                    $source_name,
                    $source_type !== '' ? $source_type : 'unknown'
                )
            );

            return $result;
        }

        if (empty($source->url)) {
            $result['errors'] = 1;

            AIJP_Logger::error(
                sprintf(
                    'RSS import failed for "%s": source URL is empty.',
                    $source_name
                )
            );

            return $result;
        }

        /*
         * Normalize source data before passing it further.
         */
        $source->id = $source_id;
        $source->name = $source_name;
        $source->url = esc_url_raw((string) $source->url);

        if ($source->url === '') {
            $result['errors'] = 1;

            AIJP_Logger::error(
                sprintf(
                    'RSS import failed for "%s": source URL is invalid.',
                    $source_name
                )
            );

            return $result;
        }

        try {
            AIJP_Logger::info(
                sprintf(
                    'RSS import started for "%s".',
                    $source_name
                )
            );

            $rss_importer = new AIJP_RSS_Importer();

            $import_result = $rss_importer->import($source);

            /*
             * Keep the importer contract stable even if the RSS importer
             * returns only part of the counters.
             */
            $result['found'] = isset($import_result['found'])
                ? (int) $import_result['found']
                : 0;

            $result['added'] = isset($import_result['added'])
                ? (int) $import_result['added']
                : 0;

            $result['duplicates'] = isset($import_result['duplicates'])
                ? (int) $import_result['duplicates']
                : 0;

            $result['skipped'] = isset($import_result['skipped'])
                ? (int) $import_result['skipped']
                : 0;

            $result['failed'] = isset($import_result['failed'])
                ? (int) $import_result['failed']
                : 0;

            /*
             * The RSS importer handles item-level failures itself.
             * They are not source-level errors.
             */
            $result['errors'] = 0;

            AIJP_Source_Repository::update_import_state(
                $source_id,
                current_time('mysql', true),
                ''
            );

            AIJP_Logger::info(
                sprintf(
                    'RSS import completed for "%s": found %d, added %d, duplicates %d, skipped %d, failed %d.',
                    $source_name,
                    $result['found'],
                    $result['added'],
                    $result['duplicates'],
                    $result['skipped'],
                    $result['failed']
                )
            );

            return $result;
        } catch (Throwable $exception) {
            $result['errors'] = 1;

            AIJP_Source_Repository::update_import_state(
                $source_id,
                null,
                $exception->getMessage()
            );

            AIJP_Logger::error(
                sprintf(
                    'RSS import failed for "%s": %s',
                    $source_name,
                    $exception->getMessage()
                )
            );

            /*
             * Do not allow one broken source to stop importing all
             * remaining sources.
             */
            return $result;
        }
    }
}
