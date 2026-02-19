<?php

namespace App\Services\Podcast;

use App\Models\Podcast;
use App\Models\PodcastEpisode;
use App\Models\User;
use App\Services\Monitoring\HttpClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RssFeedService
{
    /**
     * Import podcast from external RSS feed URL.
     */
    public function importFromUrl(string $rssUrl, User $owner): Podcast
    {
        // Fetch RSS feed
        $response = HttpClientFactory::make('rss_feed')->get($rssUrl);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch RSS feed: '.$response->status());
        }

        return $this->parseAndImport($response->body(), $rssUrl, $owner);
    }

    /**
     * Parse RSS feed and create podcast with episodes.
     */
    protected function parseAndImport(string $xmlContent, string $sourceUrl, User $owner): Podcast
    {
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($xml === false) {
            throw new \Exception('Invalid RSS feed format');
        }

        $channel = $xml->channel;
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

        return DB::transaction(function () use ($channel, $itunes, $sourceUrl, $owner) {
            // Create podcast
            $podcast = Podcast::create([
                'uuid' => Str::uuid(),
                'creator_id' => $owner->id,
                'title' => (string) $channel->title,
                'slug' => Str::slug((string) $channel->title).'-'.Str::random(6),
                'description' => (string) ($channel->description ?? ''),
                'summary' => (string) ($itunes->summary ?? $channel->description ?? ''),
                'language' => (string) ($channel->language ?? 'en'),
                'author_name' => (string) ($itunes->author ?? $owner->display_name ?? $owner->name),
                'email' => (string) ($itunes->owner->email ?? $owner->email),
                'copyright' => (string) ($channel->copyright ?? ''),
                'explicit_content' => ((string) $itunes->explicit === 'true'),
                'rss_feed_url' => $sourceUrl,
                'status' => 'draft', // Admin review before publishing
            ]);

            // Download and store cover image
            $imageUrl = (string) ($itunes->image['href'] ?? $channel->image->url ?? null);
            if ($imageUrl) {
                try {
                    $podcast->update(['artwork' => $imageUrl]); // Store external URL for now
                } catch (\Exception $e) {
                    // Continue without artwork
                }
            }

            // Import episodes
            $episodeCount = 0;
            foreach ($channel->item as $item) {
                $this->importEpisode($podcast, $item, ++$episodeCount);
            }

            return $podcast->fresh(['episodes']);
        });
    }

    /**
     * Import a single episode from RSS item.
     */
    protected function importEpisode(Podcast $podcast, \SimpleXMLElement $item, int $episodeNumber): PodcastEpisode
    {
        $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

        // Get enclosure (audio file)
        $enclosure = $item->enclosure;
        $audioUrl = $enclosure ? (string) $enclosure['url'] : null;
        $fileSize = $enclosure ? (int) $enclosure['length'] : 0;
        $mimeType = $enclosure ? (string) $enclosure['type'] : 'audio/mpeg';

        // Parse duration (could be HH:MM:SS or seconds)
        $durationStr = (string) ($itunes->duration ?? '0');
        $duration = $this->parseDuration($durationStr);

        // Get GUID
        $guid = (string) ($item->guid ?? Str::uuid());

        return PodcastEpisode::create([
            'uuid' => Str::uuid(),
            'podcast_id' => $podcast->id,
            'title' => (string) $item->title,
            'slug' => Str::slug((string) $item->title).'-'.Str::random(6),
            'description' => (string) ($item->description ?? ''),
            'show_notes' => (string) ($itunes->summary ?? $item->description ?? ''),
            'episode_number' => (int) ($itunes->episode ?? $episodeNumber),
            'season_number' => (int) ($itunes->season ?? 1),
            'episode_type' => (string) ($itunes->episodeType ?? 'full'),
            'duration' => $duration,
            'audio_file_path' => $audioUrl, // Store external URL
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'explicit' => ((string) $itunes->explicit === 'true'),
            'published_at' => $item->pubDate ? \Carbon\Carbon::parse((string) $item->pubDate) : now(),
            'status' => 'draft', // Admin review before publishing
            'external_guid' => $guid,
        ]);
    }

    /**
     * Parse duration string to seconds.
     */
    protected function parseDuration(string $duration): int
    {
        if (is_numeric($duration)) {
            return (int) $duration;
        }

        // Parse HH:MM:SS or MM:SS format
        $parts = array_reverse(explode(':', $duration));
        $seconds = 0;

        foreach ($parts as $i => $part) {
            $seconds += (int) $part * pow(60, $i);
        }

        return $seconds;
    }

    /**
     * Generate RSS 2.0 feed for a podcast.
     */
    public function generate(Podcast $podcast): string
    {
        $cacheKey = "podcast:rss:{$podcast->uuid}";
        $ttl = config('podcast.rss.ttl', 60) * 60; // Convert minutes to seconds

        return Cache::remember($cacheKey, $ttl, function () use ($podcast) {
            return $this->buildRssFeed($podcast);
        });
    }

    /**
     * Clear RSS feed cache for a podcast.
     */
    public function clearCache(Podcast $podcast): void
    {
        Cache::forget("podcast:rss:{$podcast->uuid}");
    }

    /**
     * Build complete RSS 2.0 feed.
     */
    protected function buildRssFeed(Podcast $podcast): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Create RSS root element
        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');

        // Create channel element
        $channel = $xml->createElement('channel');

        // Required channel elements
        $this->addChannelMetadata($xml, $channel, $podcast);

        // iTunes-specific elements
        $this->addITunesMetadata($xml, $channel, $podcast);

        // Add episodes
        $this->addEpisodes($xml, $channel, $podcast);

        $rss->appendChild($channel);
        $xml->appendChild($rss);

        return $xml->saveXML();
    }

    /**
     * Add basic channel metadata.
     */
    protected function addChannelMetadata(\DOMDocument $xml, \DOMElement $channel, Podcast $podcast): void
    {
        // Required RSS 2.0 elements
        $channel->appendChild($xml->createElement('title', $this->escapeXml($podcast->title)));
        $channel->appendChild($xml->createElement('link', route('podcast.show', $podcast->slug)));
        $channel->appendChild($xml->createElement('description', $this->escapeXml($podcast->description ?? $podcast->summary ?? '')));
        $channel->appendChild($xml->createElement('language', $podcast->language));

        // Generator
        $channel->appendChild($xml->createElement('generator', config('podcast.rss.generator')));

        // Copyright
        if ($podcast->copyright) {
            $channel->appendChild($xml->createElement('copyright', $this->escapeXml($podcast->copyright)));
        }

        // Last build date
        $channel->appendChild($xml->createElement('lastBuildDate', now()->toRssString()));

        // TTL (time to live)
        $channel->appendChild($xml->createElement('ttl', (string) config('podcast.rss.ttl', 60)));

        // Atom self link (best practice for podcast feeds)
        $atomLink = $xml->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $atomLink->setAttribute('href', route('api.podcast.rss', $podcast->uuid));
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atomLink);
    }

    /**
     * Add iTunes-specific podcast metadata.
     */
    protected function addITunesMetadata(\DOMDocument $xml, \DOMElement $channel, Podcast $podcast): void
    {
        // iTunes author
        $itunesAuthor = $xml->createElement('itunes:author', $this->escapeXml($podcast->author_name ?? $podcast->creator->name));
        $channel->appendChild($itunesAuthor);

        // iTunes summary
        if ($podcast->summary) {
            $itunesSummary = $xml->createElement('itunes:summary', $this->escapeXml($podcast->summary));
            $channel->appendChild($itunesSummary);
        }

        // iTunes explicit
        $itunesExplicit = $xml->createElement('itunes:explicit', $podcast->explicit_content ? 'true' : 'false');
        $channel->appendChild($itunesExplicit);

        // iTunes image
        if ($podcast->cover_image_url) {
            $itunesImage = $xml->createElement('itunes:image');
            $itunesImage->setAttribute('href', $podcast->cover_image_url);
            $channel->appendChild($itunesImage);
        }

        // iTunes category
        if ($podcast->category) {
            $itunesCategory = $xml->createElement('itunes:category');
            $itunesCategory->setAttribute('text', $podcast->category->name);

            // Add subcategory if exists
            if ($podcast->subcategory) {
                $itunesSubCategory = $xml->createElement('itunes:category');
                $itunesSubCategory->setAttribute('text', $podcast->subcategory->name);
                $itunesCategory->appendChild($itunesSubCategory);
            }

            $channel->appendChild($itunesCategory);
        }

        // iTunes owner
        $itunesOwner = $xml->createElement('itunes:owner');
        $itunesOwnerName = $xml->createElement('itunes:name', $this->escapeXml($podcast->author_name ?? $podcast->creator->name));
        $itunesOwnerEmail = $xml->createElement('itunes:email', $podcast->email ?? $podcast->creator->email);
        $itunesOwner->appendChild($itunesOwnerName);
        $itunesOwner->appendChild($itunesOwnerEmail);
        $channel->appendChild($itunesOwner);

        // iTunes type (episodic or serial)
        $itunesType = $xml->createElement('itunes:type', 'episodic');
        $channel->appendChild($itunesType);
    }

    /**
     * Add episodes to feed.
     */
    protected function addEpisodes(\DOMDocument $xml, \DOMElement $channel, Podcast $podcast): void
    {
        $episodes = $podcast->episodes()
            ->published()
            ->orderBy('published_at', 'desc')
            ->get();

        foreach ($episodes as $episode) {
            $item = $this->buildEpisodeItem($xml, $episode);
            $channel->appendChild($item);
        }
    }

    /**
     * Build individual episode item.
     */
    protected function buildEpisodeItem(\DOMDocument $xml, $episode): \DOMElement
    {
        $item = $xml->createElement('item');

        // Required item elements
        $item->appendChild($xml->createElement('title', $this->escapeXml($episode->title)));
        $item->appendChild($xml->createElement('description', $this->escapeXml($episode->description ?? '')));

        // GUID (globally unique identifier)
        $guid = $xml->createElement('guid', $episode->uuid);
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);

        // Publication date
        $item->appendChild($xml->createElement('pubDate', $episode->published_at->toRssString()));

        // Episode link
        $item->appendChild($xml->createElement('link', route('podcast.episode.show', [
            'podcast' => $episode->podcast->slug,
            'episode' => $episode->slug,
        ])));

        // Audio enclosure
        if ($episode->audio_file_path && $episode->audio_file_path !== 'temp') {
            $enclosure = $xml->createElement('enclosure');
            $enclosure->setAttribute('url', $episode->audio_url);
            $enclosure->setAttribute('length', (string) $episode->file_size);
            $enclosure->setAttribute('type', $episode->mime_type);
            $item->appendChild($enclosure);
        }

        // iTunes-specific episode metadata
        $this->addITunesEpisodeMetadata($xml, $item, $episode);

        return $item;
    }

    /**
     * Add iTunes-specific episode metadata.
     */
    protected function addITunesEpisodeMetadata(\DOMDocument $xml, \DOMElement $item, $episode): void
    {
        // iTunes duration (HH:MM:SS format)
        $duration = gmdate('H:i:s', $episode->duration);
        $item->appendChild($xml->createElement('itunes:duration', $duration));

        // iTunes episode type
        $item->appendChild($xml->createElement('itunes:episodeType', $episode->episode_type));

        // iTunes episode number
        if ($episode->episode_number) {
            $item->appendChild($xml->createElement('itunes:episode', (string) $episode->episode_number));
        }

        // iTunes season number
        if ($episode->season_number) {
            $item->appendChild($xml->createElement('itunes:season', (string) $episode->season_number));
        }

        // iTunes explicit
        $item->appendChild($xml->createElement('itunes:explicit', $episode->explicit ? 'true' : 'false'));

        // iTunes image (episode-specific artwork)
        if ($episode->artwork_url) {
            $itunesImage = $xml->createElement('itunes:image');
            $itunesImage->setAttribute('href', $episode->artwork_url);
            $item->appendChild($itunesImage);
        }

        // iTunes summary (show notes)
        if ($episode->show_notes) {
            $item->appendChild($xml->createElement('itunes:summary', $this->escapeXml(strip_tags($episode->show_notes))));
        }
    }

    /**
     * Escape XML special characters.
     */
    protected function escapeXml(string $string): string
    {
        return htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
