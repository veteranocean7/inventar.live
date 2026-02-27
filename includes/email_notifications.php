<?php
// email_notifications.php - Versiune finală cu template HTML profesional și fallback simplu

error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 0);

// Funcție simplă de logging pentru debug
if (!function_exists('logDebugEmail')) {
    function logDebugEmail($msg) {
        error_log("EMAIL: $msg");
    }
}

/**
 * Funcție helper pentru obținerea datelor utilizatorului
 */
function getDateUtilizator($id_utilizator) {
    try {
        if (!function_exists('getCentralDbConnection')) {
            logDebugEmail("getCentralDbConnection nu există!");
            return false;
        }

        $conn = getCentralDbConnection();
        if (!$conn) {
            logDebugEmail("Nu pot conecta la BD");
            return false;
        }

        // CORECȚIE: nu există coloana nume_utilizator, doar prenume și nume
        $sql = "SELECT id_utilizator, email, prenume, nume FROM utilizatori WHERE id_utilizator = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        mysqli_close($conn);

        if ($user) {
            // Construim numele complet din prenume și nume
            $user['nume_complet'] = $user['prenume'] . ' ' . $user['nume'];
            return $user;
        }

        return false;
    } catch (Exception $e) {
        logDebugEmail("Eroare getDateUtilizator: " . $e->getMessage());
        return false;
    }
}

/**
 * Versiune simplă de fallback pentru trimitere email
 */
function trimiteEmailSimplu($email_destinatar, $subiect, $mesaj_html) {
    try {
        $html = "<html><body>";
        $html .= "<h2>inventar.live</h2>";
        $html .= $mesaj_html;
        $html .= "<hr>";
        $html .= "<p style='color: #666; font-size: 12px;'>Acesta este un email automat de la inventar.live</p>";
        $html .= "</body></html>";

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: inventar.live <solicitari@inventar.live>\r\n";

        return @mail($email_destinatar, $subiect, $html, $headers);
    } catch (Exception $e) {
        logDebugEmail("Fallback email eșuat: " . $e->getMessage());
        return false;
    }
}

/**
 * Trimite notificare email cu template profesional
 * Dacă eșuează, încearcă versiunea simplă ca fallback
 */
function trimiteNotificareEmail($id_destinatar, $subiect, $mesaj, $date_suplimentare = []) {
    try {
        logDebugEmail("trimiteNotificareEmail: Start pentru destinatar ID $id_destinatar");

        // Obține datele destinatarului
        $destinatar = getDateUtilizator($id_destinatar);
        if (!$destinatar || empty($destinatar['email'])) {
            logDebugEmail("❌ Destinatar negăsit sau fără email pentru ID $id_destinatar");
            return false;
        }

        logDebugEmail("Destinatar găsit: " . $destinatar['email']);

        // Încercăm cu template-ul complet
        $html = construiesteTemplateEmail($subiect, $mesaj, $destinatar['nume_complet'], $date_suplimentare);

        // Headers pentru email HTML
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: inventar.live <solicitari@inventar.live>\r\n";
        $headers .= "Reply-To: solicitari@inventar.live\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Parametri suplimentari
        $additional_params = "-fsolicitari@inventar.live";

        // Trimite email-ul
        $success = @mail($destinatar['email'], $subiect, $html, $headers, $additional_params);

        if (!$success) {
            // Fallback la versiunea simplă
            logDebugEmail("Template complet eșuat, încerc versiunea simplă");
            $success = trimiteEmailSimplu($destinatar['email'], $subiect, $mesaj);
        }

        logDebugEmail("Rezultat trimitere: " . ($success ? "SUCCESS" : "FAILED"));
        return $success;

    } catch (Exception $e) {
        logDebugEmail("EROARE: " . $e->getMessage());
        // Încearcă fallback
        if (isset($destinatar['email'])) {
            return trimiteEmailSimplu($destinatar['email'], $subiect, $mesaj);
        }
        return false;
    }
}

/**
 * Construiește template-ul HTML profesional pentru email
 */
function construiesteTemplateEmail($subiect, $mesaj, $nume_destinatar, $date_suplimentare = []) {
    $app_url = 'https://inventar.live';

    $html = '<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($subiect) . '</title>
    <style type="text/css">
        /* Reset styles */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }

        /* Main styles */
        body {
            margin: 0 !important;
            padding: 0 !important;
            background-color: #f4f4f4 !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        @media screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 10px !important; }
            .content { padding: 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <!-- Container -->
                <table class="container" border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                    <!-- Header cu stil inventar.live -->
                    <tr>
                        <td align="center" style="
                            padding: 30px;
                            background-color: #e0e0e0;
                            background-image:
                                linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                                linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
                            background-size: 15px 15px;
                            border-radius: 10px 10px 0 0;
                            border: 2px solid #555;
                            border-top-width: 7px;
                            border-bottom: 2px solid #555;
                            box-shadow:
                                inset 0 -1px 0 rgba(0,0,0,0.1),
                                inset 0 1px 0 rgba(255,255,255,0.6);
                        ">
                            <h1 style="margin: 0; color: #2e2e40; font-size: 32px; font-weight: bold; text-shadow: 1px 1px 2px rgba(255,255,255,0.8);">
                                <a href="' . $app_url . '" style="color: #2e2e40; text-decoration: none;">📦 inventar.live</a>
                            </h1>
                            <p style="margin: 10px 0 0 0; color: #555; font-size: 14px; font-weight: 500;">
                                Organizează simplu, găsește rapid
                            </p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content" style="padding: 30px;">
                            <!-- Greeting -->
                            <h2 style="margin: 0 0 20px 0; color: #333333; font-size: 24px;">
                                Salut, ' . htmlspecialchars($nume_destinatar) . '!
                            </h2>

                            <!-- Message box cu stil inventar.live -->
                            <div style="
                                background-color: #f0f0f0;
                                border: 1px solid #ccc;
                                border-radius: 12px;
                                padding: 18px;
                                margin: 20px 0;
                                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
                            ">
                                ' . $mesaj . '
                            </div>';

    // Adaugă tabel cu informații suplimentare dacă există
    if (!empty($date_suplimentare)) {
        $html .= '
                            <!-- Info table -->
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 25px 0;">
                                <tr>
                                    <td style="padding: 0;">
                                        <table width="100%" border="0" cellspacing="0" cellpadding="10" style="background-color: #f0f0f0; border: 1px solid #ccc; border-radius: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.08);">';

        if (isset($date_suplimentare['obiect'])) {
            $html .= '
                                            <tr>
                                                <td width="30%" style="font-weight: bold; color: #2e2e40; border-bottom: 1px solid #ccc; padding: 12px;">📦 Obiect:</td>
                                                <td style="color: #333; border-bottom: 1px solid #ccc; padding: 12px;">' . htmlspecialchars($date_suplimentare['obiect']) . '</td>
                                            </tr>';
        }

        if (isset($date_suplimentare['cutie'])) {
            $html .= '
                                            <tr>
                                                <td style="font-weight: bold; color: #2e2e40; border-bottom: 1px solid #ccc; padding: 12px;">📁 Cutie:</td>
                                                <td style="color: #333; border-bottom: 1px solid #ccc; padding: 12px;">' . htmlspecialchars($date_suplimentare['cutie']) . '</td>
                                            </tr>';
        }

        if (isset($date_suplimentare['locatie'])) {
            $html .= '
                                            <tr>
                                                <td style="font-weight: bold; color: #2e2e40; border-bottom: 1px solid #ccc; padding: 12px;">📍 Locație:</td>
                                                <td style="color: #333; border-bottom: 1px solid #ccc; padding: 12px;">' . htmlspecialchars($date_suplimentare['locatie']) . '</td>
                                            </tr>';
        }

        if (isset($date_suplimentare['perioada'])) {
            $html .= '
                                            <tr>
                                                <td style="font-weight: bold; color: #2e2e40; border-bottom: 1px solid #ccc; padding: 12px;">📅 Perioada:</td>
                                                <td style="color: #333; border-bottom: 1px solid #ccc; padding: 12px;">' . htmlspecialchars($date_suplimentare['perioada']) . '</td>
                                            </tr>';
        }

        if (isset($date_suplimentare['solicitant'])) {
            $html .= '
                                            <tr>
                                                <td style="font-weight: bold; color: #2e2e40; padding: 12px;">👤 Solicitant:</td>
                                                <td style="color: #333; padding: 12px;">' . htmlspecialchars($date_suplimentare['solicitant']) . '</td>
                                            </tr>';
        }

        $html .= '
                                        </table>
                                    </td>
                                </tr>
                            </table>';
    }

    // Adaugă buton de acțiune dacă există
    if (isset($date_suplimentare['link_actiune']) && isset($date_suplimentare['text_actiune'])) {
        $html .= '
                            <!-- Action button -->
                            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="' . htmlspecialchars($date_suplimentare['link_actiune']) . '"
                                           style="display: inline-block; padding: 12px 20px; background-color: #2e2e40; color: #f0f0f0; text-decoration: none; border-radius: 10px; font-weight: 500; font-size: 16px; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                            ' . htmlspecialchars($date_suplimentare['text_actiune']) . '
                                        </a>
                                    </td>
                                </tr>
                            </table>';
    }

    $html .= '
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px; background-color: #f0f0f0; border-top: 2px solid #ccc; border-radius: 0 0 10px 10px;">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding-bottom: 10px;">
                                        <p style="margin: 0; color: #666; font-size: 13px;">
                                            Acesta este un email automat de la inventar.live
                                        </p>
                                        <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                                            Te rugăm să nu răspunzi direct la acest email
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <a href="' . $app_url . '" style="color: #2e2e40; text-decoration: none; font-size: 13px; font-weight: 500;">
                                            Vizitează inventar.live
                                        </a>
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
</html>';

    return $html;
}

/**
 * Trimite notificare pentru cerere de împrumut nouă
 */
function trimiteEmailCerereImprumut($id_proprietar, $id_solicitant, $detalii_cerere) {
    try {
        logDebugEmail("trimiteEmailCerereImprumut: Start");

        // Obține datele utilizatorilor
        $proprietar = getDateUtilizator($id_proprietar);
        $solicitant = getDateUtilizator($id_solicitant);

        if (!$proprietar || !$solicitant) {
            logDebugEmail("Nu pot obține datele utilizatorilor");
            return false;
        }

        $subiect = "📦 inventar.live - " . htmlspecialchars($solicitant['nume_complet']) . " ți-a trimis o cerere";

        $mesaj = "<strong>" . htmlspecialchars($solicitant['nume_complet']) . "</strong> pe inventar.live dorește să împrumute <strong>" .
                 htmlspecialchars($detalii_cerere['denumire_obiect']) . "</strong> din colecția ta.";

        if (!empty($detalii_cerere['mesaj'])) {
            $mesaj .= "<br><br><strong>Mesaj de la solicitant:</strong><br><em>\"" . htmlspecialchars($detalii_cerere['mesaj']) . "\"</em>";
        }

        $date_suplimentare = [
            'obiect' => $detalii_cerere['denumire_obiect'],
            'cutie' => $detalii_cerere['cutie'] ?? 'Nespecificată',
            'locatie' => $detalii_cerere['locatie'] ?? 'Nespecificată',
            'perioada' => $detalii_cerere['data_inceput'] . ' - ' . $detalii_cerere['data_sfarsit'],
            'solicitant' => $solicitant['nume_complet'],
            'link_actiune' => 'https://inventar.live/impartasiri.php?tab=imprumuturi',
            'text_actiune' => '📋 Vezi și răspunde la cerere'
        ];

        return trimiteNotificareEmail($id_proprietar, $subiect, $mesaj, $date_suplimentare);

    } catch (Exception $e) {
        logDebugEmail("Eroare în trimiteEmailCerereImprumut: " . $e->getMessage());
        return false;
    }
}

/**
 * Trimite notificare pentru răspuns la cerere
 */
function trimiteEmailRaspunsCerere($id_solicitant, $status_cerere, $detalii_cerere) {
    try {
        logDebugEmail("trimiteEmailRaspunsCerere: Start");

        // Obține datele utilizatorilor
        $solicitant = getDateUtilizator($id_solicitant);
        $proprietar = getDateUtilizator($detalii_cerere['id_proprietar']);

        if (!$solicitant || !$proprietar) {
            logDebugEmail("Nu pot obține datele utilizatorilor");
            return false;
        }

        $status_text = '';
        $emoji = '';
        $culoare = '';

        switch($status_cerere) {
            case 'aprobata':
            case 'aprobat':
                $status_text = 'APROBATĂ';
                $emoji = '✅';
                $culoare = '#28a745';
                break;
            case 'respinsa':
            case 'refuzat':
                $status_text = 'RESPINSĂ';
                $emoji = '❌';
                $culoare = '#dc3545';
                break;
            default:
                $status_text = 'ACTUALIZATĂ';
                $emoji = '📝';
                $culoare = '#667eea';
        }

        $subiect = "📦 inventar.live - Cererea ta a primit răspuns";

        $mesaj = "Cererea ta pe inventar.live pentru <strong>" . htmlspecialchars($detalii_cerere['denumire_obiect']) .
                 "</strong> a fost <span style='color: $culoare; font-weight: bold;'>$status_text</span> de către " .
                 htmlspecialchars($proprietar['nume_complet']) . ".";

        if (!empty($detalii_cerere['raspuns'])) {
            $mesaj .= "<br><br><strong>Mesaj de la proprietar:</strong><br><em>\"" .
                      htmlspecialchars($detalii_cerere['raspuns']) . "\"</em>";
        }

        $date_suplimentare = [
            'obiect' => $detalii_cerere['denumire_obiect'],
            'link_actiune' => 'https://inventar.live/impartasiri.php?tab=imprumuturi',
            'text_actiune' => '📱 Vezi detalii în aplicație'
        ];

        return trimiteNotificareEmail($id_solicitant, $subiect, $mesaj, $date_suplimentare);

    } catch (Exception $e) {
        logDebugEmail("Eroare în trimiteEmailRaspunsCerere: " . $e->getMessage());
        return false;
    }
}

/**
 * Trimite notificare pentru partajare nouă
 */
function trimiteEmailPartajareNoua($id_destinatar, $nume_colectie, $tip_acces, $nume_proprietar) {
    try {
        logDebugEmail("trimiteEmailPartajareNoua: Start");

        $destinatar = getDateUtilizator($id_destinatar);
        if (!$destinatar) {
            logDebugEmail("Nu pot obține datele destinatarului");
            return false;
        }

        $subiect = "📦 inventar.live - " . htmlspecialchars($nume_proprietar) . " ți-a partajat o colecție";

        $tip_acces_text = $tip_acces == 'scriere' ? 'completă (citire și scriere)' : 'de citire';
        $emoji_acces = $tip_acces == 'scriere' ? '✏️' : '👁️';

        $mesaj = "<strong>" . htmlspecialchars($nume_proprietar) . "</strong> pe inventar.live ți-a acordat acces la colecția <strong>" .
                 htmlspecialchars($nume_colectie) . "</strong> cu permisiuni $emoji_acces <strong>$tip_acces_text</strong>.";

        $mesaj .= "<br><br>Acum poți vedea și " . ($tip_acces == 'scriere' ? 'modifica' : 'consulta') .
                  " toate obiectele din această colecție.";

        $date_suplimentare = [
            'link_actiune' => 'https://inventar.live',
            'text_actiune' => '🚀 Accesează colecția'
        ];

        return trimiteNotificareEmail($id_destinatar, $subiect, $mesaj, $date_suplimentare);

    } catch (Exception $e) {
        logDebugEmail("Eroare în trimiteEmailPartajareNoua: " . $e->getMessage());
        return false;
    }
}

/**
 * Trimite notificare pentru revocare acces
 */
function trimiteEmailRevocareAcces($id_destinatar, $nume_colectie, $nume_proprietar) {
    try {
        logDebugEmail("trimiteEmailRevocareAcces: Start");

        $destinatar = getDateUtilizator($id_destinatar);
        if (!$destinatar) {
            logDebugEmail("Nu pot obține datele destinatarului");
            return false;
        }

        $subiect = "📦 inventar.live - Acces revocat la o colecție";

        $mesaj = "<strong>" . htmlspecialchars($nume_proprietar) . "</strong> pe inventar.live ți-a revocat accesul la colecția <strong>" .
                 htmlspecialchars($nume_colectie) . "</strong>.";

        $mesaj .= "<br><br>Nu mai ai acces la obiectele din această colecție. Dacă ai întrebări, " .
                  "te rugăm să contactezi direct proprietarul colecției.";

        return trimiteNotificareEmail($id_destinatar, $subiect, $mesaj);

    } catch (Exception $e) {
        logDebugEmail("Eroare în trimiteEmailRevocareAcces: " . $e->getMessage());
        return false;
    }
}

?>