<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <!--[if !mso]><!-->
    <style>
        @media only screen and (max-width: 620px) {
            .email-container {
                width: 100% !important;
            }
            .email-content {
                padding: 20px 16px !important;
            }
            .email-header {
                padding: 20px 16px !important;
            }
        }
    </style>
    <!--<![endif]-->
</head>
<body style="padding: 0; background-color: <?= htmlspecialchars($body_bg_color) ?>;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: <?= htmlspecialchars($body_bg_color) ?>; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
        <tr>
            <td align="center" style="padding: 24px 12px;">

                <!-- Main container -->
                <table role="presentation" class="email-container" width="600" cellspacing="0" cellpadding="0" border="0">

                    <!-- Header -->
                    <tr>
                        <td class="email-header" align="center" style="background-color: <?= htmlspecialchars($primary_color) ?>; padding: 28px 24px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="color: #FFFFFF; font-size: 22px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                                        <?= htmlspecialchars($site_name) ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="email-content" style="background-color: <?= htmlspecialchars($content_bg_color) ?>; padding: 32px 28px; color: <?= htmlspecialchars($text_color) ?>; font-size: 15px; line-height: 1.6;">
                            <?= $content ?>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color: <?= htmlspecialchars($content_bg_color) ?>; padding: 20px 28px 28px; border-top: 1px solid #eeeeee;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="color: <?= htmlspecialchars($muted_color) ?>; font-size: 12px; line-height: 1.5;">
                                        Enviado desde <?= htmlspecialchars($site_name) ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>
</body>
</html>
