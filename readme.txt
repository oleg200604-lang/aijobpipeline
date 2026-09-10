=== AI Job Pipeline ===
Contributors: ai-job-pipeline
Tags: jobs, rss, openai, automation
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPL-2.0-or-later

Imports freelance jobs from configured RSS sources and analyzes queued jobs with OpenAI.

== Description ==

AI Job Pipeline provides an administrative workflow for:

* managing RSS sources;
* importing and deduplicating jobs;
* running AI analysis manually or through WP-Cron;
* reviewing job details, analysis results, logs, and AI usage;
* enforcing configurable daily and monthly AI spending limits.

API credentials are currently stored in the WordPress options table. Protect database and administration access accordingly.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate AI Job Pipeline.
3. Open AI Job Pipeline > Settings and configure the OpenAI API key, model, budgets, and cron interval.
4. Add at least one enabled RSS source.
5. Use Sources > Run import now, or wait for WP-Cron.
6. Open AI Queue to run or review the AI pipeline.

== Changelog ==

= 0.3.1 =

* Restored the automatic and manual RSS import pipeline.
* Registered and loaded the AI pipeline runner.
* Fixed AI usage storage and budget calculations.
* Aligned API key, timeout, temperature, and cron settings with runtime behavior.
* Fixed object-backed job processing and analysis metadata persistence.
* Applied plugin capabilities to administration pages.
* Fixed source and job status displays.

== Uninstall ==

Uninstalling clears scheduled plugin events and the transient pipeline lock. Imported jobs, analyses, settings, and logs are intentionally retained to prevent accidental data loss.
