<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="padding: 0 0 20px; font-size: 20px; color: #1a1a1a;">
            <strong>Nuevo mensaje de contacto</strong>
        </td>
    </tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding: 0 0 24px;">
    <tr>
        <td width="100" style="padding: 12px 8px 12px 0; border-bottom: 1px solid #eeeeee; color: #666666; font-size: 13px; vertical-align: top;">NOMBRE</td>
        <td style="padding: 12px 0; border-bottom: 1px solid #eeeeee; color: #1a1a1a;"><?= $name ?? '' ?></td>
    </tr>
    <tr>
        <td width="100" style="padding: 12px 8px 12px 0; border-bottom: 1px solid #eeeeee; color: #666666; font-size: 13px; vertical-align: top;">EMAIL</td>
        <td style="padding: 12px 0; border-bottom: 1px solid #eeeeee;"><a href="mailto:<?= $email ?? '' ?>" style="color: #2D5F2B;"><?= $email ?? '' ?></a></td>
    </tr>
<?php if (!empty($phone)): ?>
    <tr>
        <td width="100" style="padding: 12px 8px 12px 0; border-bottom: 1px solid #eeeeee; color: #666666; font-size: 13px; vertical-align: top;">TEL&Eacute;FONO</td>
        <td style="padding: 12px 0; border-bottom: 1px solid #eeeeee; color: #1a1a1a;"><?= $phone ?></td>
    </tr>
<?php endif; ?>
    <tr>
        <td width="100" style="padding: 12px 8px 12px 0; color: #666666; font-size: 13px; vertical-align: top;">MENSAJE</td>
        <td style="padding: 12px 0; color: #1a1a1a;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color: #f9f9fb; padding: 16px; border: 1px solid #eeeeee;"><?= nl2br($message ?? '') ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="padding: 12px 16px; background-color: #f0f7f0; color: #555555; font-size: 13px; line-height: 1.5;">
            Puedes responder directamente a este correo para contactar al remitente.
        </td>
    </tr>
</table>
