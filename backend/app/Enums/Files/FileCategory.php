<?php

namespace App\Enums\Files;

enum FileCategory: string
{
    case CompanyLoginBackground = 'company_login_background';
    case CompanyLogo = 'company_logo';
    case EquipmentPhoto = 'equipment_photo';
    case OrderPhoto = 'order_photo';
    case OrderPdf = 'order_pdf';
    case BudgetPdf = 'budget_pdf';
    case UserSignature = 'user_signature';
    case UserProfilePhoto = 'user_profile_photo';
    case ChatAttachment = 'chat_attachment';
    // XML e DANFSe baixados do portal (spec 041, fase 042). O XML e' o que a
    // lei manda guardar por 5 anos.
    case FiscalDocument = 'fiscal_document';
}
