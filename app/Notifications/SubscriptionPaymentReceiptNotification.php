<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionPaymentReceiptNotification extends Notification
{
    use Queueable;

    protected $transactionData;

    /**
     * Create a new notification instance.
     * 
     * @param array $transactionData [
     *     'receipt_no' => string (transaction ID or reference number),
     *     'plan_name' => string,
     *     'amount' => float,
     *     'payment_method' => string,
     *     'transaction_date' => Carbon datetime,
     *     'start_date' => string,
     *     'end_date' => string,
     *     'user_name' => string (optional)
     * ]
     */
    public function __construct(array $transactionData)
    {
        $this->transactionData = $transactionData;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $userName = $this->transactionData['user_name'] ?? $notifiable->firstname ?? 'Valued Customer';
        $receiptNo = $this->transactionData['receipt_no'];
        $planName = $this->transactionData['plan_name'];
        $amount = number_format($this->transactionData['amount'], 2);
        $paymentMethod = $this->transactionData['payment_method'];
        $transactionDate = $this->transactionData['transaction_date']->format('F d, Y h:i A');
        $startDate = $this->transactionData['start_date'];
        $endDate = $this->transactionData['end_date'];

        // Format reference number like GCash reference (e.g., AIDP-2025103017-000050)
        $formattedRefNo = $this->formatReferenceNumber($receiptNo);

        return (new MailMessage)
            ->subject('Payment Receipt - Subscription to ' . $planName)
            ->greeting('Hello ' . $userName . '!')
            ->line('Thank you for your payment! Your subscription has been successfully activated.')
            ->line('**Reference No:** ' . $formattedRefNo)
            ->line('---')
            ->line('**Transaction Details:**')
            ->line('• Plan: ' . $planName)
            ->line('• Amount Paid: ₱' . $amount)
            ->line('• Payment Method: ' . $paymentMethod)
            ->line('• Transaction Date: ' . $transactionDate)
            ->line('---')
            ->line('**Subscription Period:**')
            ->line('• Start Date: ' . $startDate)
            ->line('• End Date: ' . $endDate)
            ->line('---')
            ->line('You can view your subscription details and transaction history anytime in your account dashboard.')
            ->action('View My Subscriptions', url('/app/subscriptions'))
            ->line('If you have any questions or concerns about this transaction, please contact our support team.')
            ->salutation('Thank you for choosing AidPoint!');
    }

    /**
     * Format the reference number to look like a GCash/payment gateway reference.
     * Example format: AIDP-2025103017-000050
     */
    private function formatReferenceNumber($transactionId): string
    {
        $timestamp = $this->transactionData['transaction_date']->format('YmdHi');
        $paddedId = str_pad($transactionId, 6, '0', STR_PAD_LEFT);
        return 'AIDP-' . $timestamp . '-' . $paddedId;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'receipt_no' => $this->transactionData['receipt_no'],
            'plan_name' => $this->transactionData['plan_name'],
            'amount' => $this->transactionData['amount'],
            'payment_method' => $this->transactionData['payment_method'],
        ];
    }
}
