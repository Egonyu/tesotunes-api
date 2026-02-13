<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Models\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Event Settings Service
 * 
 * Handles all business logic related to event and ticketing settings.
 * This service centralizes event configuration management and provides
 * reusable methods for event-related business rules.
 */
class EventSettingsService
{
    /**
     * Get all event-related settings.
     * 
     * @return array
     */
    public function getSettings(): array
    {
        return [
            // General settings
            'events_enabled' => Setting::get('events_enabled', true),
            'events_require_approval' => Setting::get('events_require_approval', true),
            'max_events_per_artist' => Setting::get('max_events_per_artist', 5),
            'event_lead_time' => Setting::get('event_lead_time', 7),
            
            // Ticketing settings
            'paid_tickets_enabled' => Setting::get('paid_tickets_enabled', true),
            'min_ticket_price' => Setting::get('min_ticket_price', 5000),
            'max_ticket_price' => Setting::get('max_ticket_price', 500000),
            'ticket_verification' => Setting::get('ticket_verification', true),
            
            // Fee settings
            'platform_commission' => Setting::get('platform_commission', 10),
            'processing_fee' => Setting::get('processing_fee', 2.9),
            'auto_calculate_total' => Setting::get('auto_calculate_total', true),
            'refund_fee' => Setting::get('refund_fee', 5),
            'cancellation_period' => Setting::get('cancellation_period', 24),
        ];
    }

    /**
     * Update general event settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateGeneralSettings(array $data): bool
    {
        try {
            $settings = [
                'events_enabled' => $data['events_enabled'] ?? true,
                'events_require_approval' => $data['events_require_approval'] ?? true,
                'max_events_per_artist' => (int) ($data['max_events_per_artist'] ?? 5),
                'event_lead_time' => (int) ($data['event_lead_time'] ?? 7),
            ];

            // Validate max events per artist
            if ($settings['max_events_per_artist'] < 1 || $settings['max_events_per_artist'] > 100) {
                Log::warning('Invalid max_events_per_artist value', ['value' => $settings['max_events_per_artist']]);
                return false;
            }

            // Validate event lead time
            if ($settings['event_lead_time'] < 0 || $settings['event_lead_time'] > 365) {
                Log::warning('Invalid event_lead_time value', ['value' => $settings['event_lead_time']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_EVENTS);
            }

            Log::info('Event general settings updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update event general settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update ticketing settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateTicketingSettings(array $data): bool
    {
        try {
            $settings = [
                'paid_tickets_enabled' => $data['paid_tickets_enabled'] ?? true,
                'min_ticket_price' => (float) ($data['min_ticket_price'] ?? 5000),
                'max_ticket_price' => (float) ($data['max_ticket_price'] ?? 500000),
                'ticket_verification' => $data['ticket_verification'] ?? true,
            ];

            // Validate ticket price range
            if ($settings['min_ticket_price'] < 0) {
                Log::warning('Min ticket price cannot be negative', ['value' => $settings['min_ticket_price']]);
                return false;
            }

            if ($settings['max_ticket_price'] < $settings['min_ticket_price']) {
                Log::warning('Max ticket price must be greater than min ticket price', [
                    'min' => $settings['min_ticket_price'],
                    'max' => $settings['max_ticket_price']
                ]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_EVENTS);
            }

            Log::info('Event ticketing settings updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update event ticketing settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update fee settings.
     * 
     * @param array $data
     * @return bool
     */
    public function updateFeeSettings(array $data): bool
    {
        try {
            $settings = [
                'platform_commission' => (float) ($data['platform_commission'] ?? 10),
                'processing_fee' => (float) ($data['processing_fee'] ?? 2.9),
                'auto_calculate_total' => $data['auto_calculate_total'] ?? true,
                'refund_fee' => (float) ($data['refund_fee'] ?? 5),
                'cancellation_period' => (int) ($data['cancellation_period'] ?? 24),
            ];

            // Validate commission percentage
            if ($settings['platform_commission'] < 0 || $settings['platform_commission'] > 50) {
                Log::warning('Platform commission must be between 0-50%', ['value' => $settings['platform_commission']]);
                return false;
            }

            // Validate processing fee
            if ($settings['processing_fee'] < 0 || $settings['processing_fee'] > 10) {
                Log::warning('Processing fee must be between 0-10%', ['value' => $settings['processing_fee']]);
                return false;
            }

            // Validate refund fee
            if ($settings['refund_fee'] < 0 || $settings['refund_fee'] > 100) {
                Log::warning('Refund fee must be between 0-100%', ['value' => $settings['refund_fee']]);
                return false;
            }

            // Validate cancellation period
            if ($settings['cancellation_period'] < 0) {
                Log::warning('Cancellation period cannot be negative', ['value' => $settings['cancellation_period']]);
                return false;
            }

            foreach ($settings as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : Setting::TYPE_NUMBER;
                Setting::set($key, $value, $type, Setting::GROUP_EVENTS);
            }

            Log::info('Event fee settings updated successfully', [
                'admin_id' => auth()->id(),
                'settings' => array_keys($settings)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update event fee settings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    // ==================== Business Logic Methods ====================

    /**
     * Check if events system is enabled.
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return Setting::get('events_enabled', true);
    }

    /**
     * Check if event approval is required.
     * 
     * @return bool
     */
    public function isApprovalRequired(): bool
    {
        return Setting::get('events_require_approval', true);
    }

    /**
     * Check if paid tickets are enabled.
     * 
     * @return bool
     */
    public function isPaidTicketsEnabled(): bool
    {
        return Setting::get('paid_tickets_enabled', true);
    }

    /**
     * Get maximum events an artist can create per month.
     * 
     * @return int
     */
    public function getMaxEventsPerArtist(): int
    {
        return Setting::get('max_events_per_artist', 5);
    }

    /**
     * Get event creation lead time in days.
     * 
     * @return int
     */
    public function getEventLeadTime(): int
    {
        return Setting::get('event_lead_time', 7);
    }

    /**
     * Get platform commission percentage.
     * 
     * @return float
     */
    public function getPlatformCommission(): float
    {
        return Setting::get('platform_commission', 10);
    }

    /**
     * Get payment processing fee percentage.
     * 
     * @return float
     */
    public function getProcessingFee(): float
    {
        return Setting::get('processing_fee', 2.9);
    }

    /**
     * Get refund fee percentage.
     * 
     * @return float
     */
    public function getRefundFee(): float
    {
        return Setting::get('refund_fee', 5);
    }

    /**
     * Get cancellation period in hours.
     * 
     * @return int
     */
    public function getCancellationPeriod(): int
    {
        return Setting::get('cancellation_period', 24);
    }

    /**
     * Check if an artist can create more events this month.
     * 
     * @param User $artist
     * @return bool
     */
    public function canArtistCreateEvent(User $artist): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $maxEvents = $this->getMaxEventsPerArtist();
        
        $eventsThisMonth = DB::table('events')
            ->where('user_id', $artist->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return $eventsThisMonth < $maxEvents;
    }

    /**
     * Calculate total ticket price including all fees.
     * 
     * @param float $basePrice
     * @return float
     */
    public function calculateTotalPrice(float $basePrice): float
    {
        $commission = $this->getPlatformCommission() / 100;
        $processingFee = $this->getProcessingFee() / 100;
        
        $platformCut = $basePrice * $commission;
        $processingCut = $basePrice * $processingFee;
        
        return round($basePrice + $platformCut + $processingCut, 2);
    }

    /**
     * Calculate artist payout from ticket sales.
     * 
     * @param float $ticketRevenue
     * @return float
     */
    public function calculateArtistPayout(float $ticketRevenue): float
    {
        $commission = $this->getPlatformCommission() / 100;
        $processingFee = $this->getProcessingFee() / 100;
        
        $platformCut = $ticketRevenue * $commission;
        $processingCut = $ticketRevenue * $processingFee;
        
        return round($ticketRevenue - $platformCut - $processingCut, 2);
    }

    /**
     * Calculate breakdown of fees for a ticket price.
     * 
     * @param float $basePrice
     * @return array
     */
    public function calculateFeeBreakdown(float $basePrice): array
    {
        $commission = $this->getPlatformCommission() / 100;
        $processingFee = $this->getProcessingFee() / 100;
        
        $platformCut = round($basePrice * $commission, 2);
        $processingCut = round($basePrice * $processingFee, 2);
        $totalPrice = $basePrice + $platformCut + $processingCut;
        $artistReceives = $basePrice;

        return [
            'base_price' => $basePrice,
            'platform_commission' => $platformCut,
            'processing_fee' => $processingCut,
            'total_price' => $totalPrice,
            'artist_receives' => $artistReceives,
        ];
    }

    /**
     * Check if refund is allowed for an event based on cancellation period.
     * 
     * @param \DateTime $eventDate
     * @return bool
     */
    public function isRefundAllowed(\DateTime $eventDate): bool
    {
        $cancellationPeriod = $this->getCancellationPeriod();
        $hoursUntilEvent = ($eventDate->getTimestamp() - time()) / 3600;
        
        return $hoursUntilEvent >= $cancellationPeriod;
    }

    /**
     * Calculate refund amount after deducting refund fee.
     * 
     * @param float $ticketPrice
     * @return float
     */
    public function calculateRefundAmount(float $ticketPrice): float
    {
        $refundFee = $this->getRefundFee() / 100;
        $refundAmount = $ticketPrice - ($ticketPrice * $refundFee);
        
        return round($refundAmount, 2);
    }

    /**
     * Validate ticket price is within allowed range.
     * 
     * @param float $price
     * @return bool
     */
    public function isValidTicketPrice(float $price): bool
    {
        $minPrice = Setting::get('min_ticket_price', 5000);
        $maxPrice = Setting::get('max_ticket_price', 500000);
        
        return $price >= $minPrice && $price <= $maxPrice;
    }

    /**
     * Check if event date meets minimum lead time requirement.
     * 
     * @param \DateTime $eventDate
     * @return bool
     */
    public function meetsLeadTimeRequirement(\DateTime $eventDate): bool
    {
        $leadTime = $this->getEventLeadTime();
        $daysUntilEvent = ($eventDate->getTimestamp() - time()) / 86400; // seconds to days
        
        return $daysUntilEvent >= $leadTime;
    }

    /**
     * Get event statistics.
     * 
     * @return array
     */
    public function getEventStatistics(): array
    {
        return [
            'total_events' => DB::table('events')->count(),
            'upcoming_events' => DB::table('events')
                ->where('starts_at', '>', now())
                ->where('status', 'published')
                ->count(),
            'past_events' => DB::table('events')
                ->where('starts_at', '<', now())
                ->count(),
            'pending_approval' => DB::table('events')
                ->where('status', 'draft')
                ->count(),
            'total_tickets_sold' => DB::table('event_attendees')->count(),
            'total_revenue' => DB::table('event_tickets')
                ->join('event_attendees', 'event_tickets.id', '=', 'event_attendees.ticket_id')
                ->sum('event_tickets.price') ?? 0,
        ];
    }
}
