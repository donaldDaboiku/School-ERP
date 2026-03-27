<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Refund</title>
</head>
<body>
    <p>Hello {{ $student->full_name }},</p>

    <p>
        A refund has been processed for the following payment:
    </p>

    <ul>
        <li>Invoice Number: {{ $payment->invoice_number }}</li>
        <li>Refund Amount: {{ number_format($refund_amount, 2) }}</li>
        <li>Refund Date: {{ $refund_date }}</li>
        <li>Reason: {{ $reason }}</li>
    </ul>

    <p>
        If you have any questions, please contact {{ $school->name }}.
    </p>

    <p>
        Regards,<br>
        {{ $school->name }}
    </p>
</body>
</html>
