<?php

namespace App\Enums;

enum LedgerType: string
{
    case SaleAccrual = 'SALE_ACCRUAL';
    case RefundReversal = 'REFUND_REVERSAL';
    case Adjustment = 'ADJUSTMENT';
    case SettlementPayment = 'SETTLEMENT_PAYMENT';
    case CarryOver = 'CARRY_OVER';
}
