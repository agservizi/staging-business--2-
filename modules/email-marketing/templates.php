<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Template email marketing';

$presetTemplates = [
    [
        'id' => 'service-showcase',
        'name' => 'Vetrina servizi principali',
        'subject' => 'Scopri tutti i servizi disponibili in agenzia',
        'preheader' => 'Pagamenti, spedizioni, attivazioni digitali e assistenza dedicata',
        'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>AG Servizi - Servizi in evidenza</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; background-color:#f4f6fb; font-family:'Segoe UI',Helvetica,Arial,sans-serif; color:#1b1f3b;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6fb;">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="background-color:#ffffff; border-radius:16px; overflow:hidden;">
                    <tr>
                        <td style="padding:32px; background:linear-gradient(135deg,#0b2f6b,#1f5db8); color:#ffffff;">
                            <p style="margin:0 0 12px; font-size:13px; letter-spacing:0.12em; text-transform:uppercase;">Novita dal punto servizi</p>
                            <h1 style="margin:0 0 16px; font-size:28px; line-height:1.2;">Ciao {{first_name}}, scopri tutto il mondo AG Servizi</h1>
                            <p style="margin:0 0 24px; font-size:16px; line-height:1.6;">Pagamenti istantanei, spedizioni sicure e attivazioni digitali certificate. Il nostro team ti assiste in ogni pratica quotidiana.</p>
                            <a href="https://www.agenziaplinio.it/servizi" style="display:inline-block; padding:12px 24px; background-color:#ffc107; color:#0b2f6b; border-radius:999px; font-weight:600; text-decoration:none;">Scopri tutti i servizi</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:12px; border:1px solid #dde3f1;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/service-image-1743606275426-pagamenti-uUXVejJrRwBKjHfIa5fw0z8o4HnnE5" alt="Pagamenti e bollettini" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:16px 24px;">
                                                    <p style="margin:0 0 8px; font-size:14px; color:#0b2f6b; letter-spacing:0.08em; text-transform:uppercase;">Pagamenti e Bollettini</p>
                                                    <h2 style="margin:0 0 12px; font-size:20px; color:#112044;">Paga tutto in un solo sportello</h2>
                                                    <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#334155;">Bollettini, F24, PagoPA, MAV/RAV e bonifici DropPoint con ricevuta immediata e archivio digitale delle pratiche.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/pagamenti" style="display:inline-block; padding:10px 18px; border:2px solid #0b2f6b; border-radius:999px; color:#0b2f6b; text-decoration:none; font-weight:600;">Prenota ora</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:12px; border:1px solid #dde3f1;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/brt-XmfBclOgJHjfimzC2bpN17zzIQC5xT.jpg" alt="Spedizioni BRT" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:16px 24px;">
                                                    <p style="margin:0 0 8px; font-size:14px; color:#0b2f6b; letter-spacing:0.08em; text-transform:uppercase;">Spedizioni</p>
                                                    <h2 style="margin:0 0 12px; font-size:20px; color:#112044;">Spedizioni nazionali e internazionali</h2>
                                                    <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#334155;">Con BRT, Poste Italiane e TNT/FedEx spediamo i tuoi pacchi con tracking in tempo reale, assicurazione dedicata e consegna su appuntamento.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/spedizioni" style="display:inline-block; padding:10px 18px; border:2px solid #0b2f6b; border-radius:999px; color:#0b2f6b; text-decoration:none; font-weight:600;">Spedisci con noi</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:12px; border:1px solid #dde3f1;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/namirial-qwWloPu8WTylnGifUpwOWoHEU28Uwb.png" alt="Attivazioni digitali" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:16px 24px;">
                                                    <p style="margin:0 0 8px; font-size:14px; color:#0b2f6b; letter-spacing:0.08em; text-transform:uppercase;">Attivazioni digitali</p>
                                                    <h2 style="margin:0 0 12px; font-size:20px; color:#112044;">SPID, PEC e Firma Digitale in pochi minuti</h2>
                                                    <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#334155;">Siamo partner Namirial per SPID, PEC e Firma Digitale. Prepariamo tutta la documentazione e ti consegniamo i codici pronti all&apos;uso.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/attivazioni-digitali" style="display:inline-block; padding:10px 18px; border:2px solid #0b2f6b; border-radius:999px; color:#0b2f6b; text-decoration:none; font-weight:600;">Richiedi attivazione</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px; border-radius:12px; background-color:#f7f9ff; border:1px dashed #b4c3e0;">
                                <tr>
                                    <td style="padding:24px; text-align:center;">
                                        <h3 style="margin:0 0 12px; font-size:18px; color:#0b2f6b;">Serve supporto dedicato?</h3>
                                        <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#334155;">Analizziamo insieme le tue esigenze e costruiamo un piano personalizzato per la tua famiglia o la tua attivit&agrave; professionale.</p>
                                        <a href="https://www.agenziaplinio.it/contatti" style="display:inline-block; padding:12px 26px; background-color:#0b2f6b; border-radius:999px; color:#ffffff; text-decoration:none; font-weight:600;">Prenota un appuntamento</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e5eaf5;">
                                <tr>
                                    <td style="padding:24px 0 0; font-size:14px; line-height:1.6; color:#334155;">
                                        <strong>AG Servizi Via Plinio 72</strong><br>
                                        Via Plinio il Vecchio 72, Castellammare di Stabia (NA)<br>
                                        Tel. +39 081 0584542 &middot; info@agenziaplinio.it
                                    </td>
                                    <td style="padding:24px 0 0; text-align:right;">
                                        <a href="https://www.facebook.com/agserviziplinio.it" style="display:inline-block; margin-left:8px; color:#0b2f6b; text-decoration:none; font-weight:600;">Facebook</a>
                                        <a href="https://www.instagram.com/agenziaplinio" style="display:inline-block; margin-left:8px; color:#0b2f6b; text-decoration:none; font-weight:600;">Instagram</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 32px; text-align:center; font-size:12px; line-height:1.6; color:#6c7d93;">
                            Non vuoi ricevere altre comunicazioni? <a href="{{unsubscribe_url}}" style="color:#0b2f6b; text-decoration:underline;">Disiscriviti</a> in qualsiasi momento.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
    ],
    [
        'id' => 'energia-connettivita',
        'name' => 'Energia e connettivit&agrave;',
        'subject' => 'Risparmia su luce, gas e connettivit&agrave; con AG Servizi',
        'preheader' => 'Check-up gratuito delle bollette e offerte dedicate per casa e business',
        'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>AG Servizi - Energia e Connettivita</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; background-color:#eef4ff; font-family:'Segoe UI',Helvetica,Arial,sans-serif; color:#102349;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#eef4ff;">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="background-color:#ffffff; border-radius:18px; overflow:hidden;">
                    <tr>
                        <td style="padding:36px; background:linear-gradient(135deg,#123b78,#1f7dd1); color:#ffffff;">
                            <p style="margin:0 0 10px; font-size:12px; letter-spacing:0.14em; text-transform:uppercase;">Consulenza personalizzata</p>
                            <h1 style="margin:0 0 18px; font-size:30px; line-height:1.2;">Ciao {{first_name}}, ottimizziamo luce, gas e connettivit&agrave;</h1>
                            <p style="margin:0 0 26px; font-size:16px; line-height:1.7;">Analizziamo le tue bollette attuali e proponiamo le migliori soluzioni con A2A Energia, Enel Energia, Fastweb, Iliad, WindTre, Pianeta Fibra e molti altri partner.</p>
                            <a href="https://www.agenziaplinio.it/servizi/telefonia-luce-gas" style="display:inline-block; padding:12px 28px; border-radius:999px; background-color:#ffda47; color:#0b2f6b; font-weight:600; text-decoration:none;">Prenota la tua analisi gratuita</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d7e2f5;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/consulenza-utenze-mnvuZsScuMyQ7x7vzXJWfeVAj0Ju8l.jpg" alt="Analisi bollette" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:18px 24px;">
                                                    <p style="margin:0 0 8px; font-size:13px; color:#0b2f6b; letter-spacing:0.1em; text-transform:uppercase;">Check-up bollette</p>
                                                    <h2 style="margin:0 0 12px; font-size:21px; color:#102349;">Riduci i costi energetici in modo trasparente</h2>
                                                    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#364863;">Ti prepariamo un report dettagliato con simulazione dei risparmi su luce e gas, tariffe fisse o variabili e piano di passaggio senza interruzioni del servizio.</p>
                                                    <p style="margin:0; font-size:14px; color:#0b2f6b; font-weight:600;">Porta con te: ultime 2 bollette, codice POD/PDR, documento e IBAN.</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d7e2f5;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/treno-YWoVrIKusxhlIG6eVmhOd0YiNsJ5A6.jpg" alt="Connettivita veloce" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:18px 24px;">
                                                    <p style="margin:0 0 8px; font-size:13px; color:#0b2f6b; letter-spacing:0.1em; text-transform:uppercase;">Fibra e mobile</p>
                                                    <h2 style="margin:0 0 12px; font-size:21px; color:#102349;">Connessioni pronte per smart working e intrattenimento</h2>
                                                    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#364863;">Fastweb, Iliad, WindTre, Pianeta Fibra e Sky: scegli l&apos;offerta migliore per navigare ad alta velocit&agrave; e avere minuti illimitati senza sorprese in bolletta.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/telefonia-luce-gas" style="display:inline-block; padding:10px 20px; border-radius:999px; border:2px solid #0b2f6b; color:#0b2f6b; text-decoration:none; font-weight:600;">Scopri le offerte</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d7e2f5; background-color:#f9fbff;">
                                            <tr>
                                                <td style="padding:24px;">
                                                    <h3 style="margin:0 0 12px; font-size:18px; color:#102349;">Perch&eacute; scegliere la nostra agenzia</h3>
                                                    <ul style="margin:0; padding:0 0 0 18px; font-size:15px; line-height:1.7; color:#364863;">
                                                        <li>Gestione completa pratiche di voltura, subentro e nuove attivazioni.</li>
                                                        <li>Supporto continuativo post attivazione con canali dedicati.</li>
                                                        <li>Archivio digitale delle pratiche e reminder scadenze contrattuali.</li>
                                                    </ul>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; background-color:#0b2f6b;">
                                <tr>
                                    <td style="padding:26px; text-align:center; color:#ffffff;">
                                        <p style="margin:0 0 8px; font-size:14px; letter-spacing:0.08em; text-transform:uppercase;">Checklist rapida</p>
                                        <p style="margin:0 0 18px; font-size:18px; font-weight:600;">Prepara i documenti e passa in agenzia senza attese</p>
                                        <a href="https://www.agenziaplinio.it/contatti" style="display:inline-block; padding:12px 26px; border-radius:999px; background-color:#ffda47; color:#0b2f6b; font-weight:600; text-decoration:none;">Prenota in agenda</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #d7e2f5;">
                                <tr>
                                    <td style="padding:22px 0 0; font-size:14px; line-height:1.6; color:#364863;">
                                        <strong>AG Servizi Via Plinio 72</strong><br>
                                        Via Plinio il Vecchio 72, Castellammare di Stabia (NA)<br>
                                        Tel. +39 081 0584542 &middot; info@agenziaplinio.it
                                    </td>
                                    <td style="padding:22px 0 0; text-align:right;">
                                        <a href="https://www.agenziaplinio.it/servizi/telefonia-luce-gas" style="display:inline-block; margin-left:8px; color:#0b2f6b; text-decoration:none; font-weight:600;">Dettagli servizio</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px 34px; text-align:center; font-size:12px; line-height:1.6; color:#6c7d93;">
                            Se non vuoi ricevere aggiornamenti sulle offerte energia e connettivit&agrave; puoi <a href="{{unsubscribe_url}}" style="color:#0b2f6b; text-decoration:underline;">disiscriverti qui</a>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
    ],
    [
        'id' => 'news-community',
        'name' => 'News e servizi territoriali',
        'subject' => 'Novit&agrave;, pratiche CAF e ritiro pacchi: tutte le news AG Servizi',
        'preheader' => 'Aggiornamenti dal punto servizi: CAF, patronato, visure e punto ritiro pacchi',
        'html' => <<<'HTML'
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>AG Servizi - News e territorio</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0; background-color:#f3f5f9; font-family:'Segoe UI',Helvetica,Arial,sans-serif; color:#1a2742;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f5f9;">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="background-color:#ffffff; border-radius:18px; overflow:hidden;">
                    <tr>
                        <td style="padding:34px; background:linear-gradient(135deg,#152a52,#244b86); color:#ffffff;">
                            <p style="margin:0 0 10px; font-size:12px; letter-spacing:0.16em; text-transform:uppercase;">Community update</p>
                            <h1 style="margin:0 0 18px; font-size:29px; line-height:1.2;">Ciao {{first_name}}, ecco le novit&agrave; del nostro punto servizi</h1>
                            <p style="margin:0; font-size:16px; line-height:1.7;">Supporto CAF e patronato, pratiche certificate e servizi di logistica a pochi passi da casa tua.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d6deef;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/cafpatronato-IYvk759MyjLrJWvwVmoWlFfqzKUFOB.jpg" alt="CAF e Patronato" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:18px 24px;">
                                                    <p style="margin:0 0 8px; font-size:13px; color:#244b86; letter-spacing:0.1em; text-transform:uppercase;">CAF e Patronato</p>
                                                    <h2 style="margin:0 0 12px; font-size:21px; color:#1a2742;">730, ISEE, NASpI e pratiche previdenziali</h2>
                                                    <p style="margin:0 0 14px; font-size:15px; line-height:1.7; color:#3a4c69;">Il nostro desk dedicato verifica la documentazione, invia le domande e ti aggiorna sullo stato della pratica con promemoria automatici.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/caf-patronato" style="display:inline-block; padding:10px 20px; border-radius:999px; border:2px solid #244b86; color:#244b86; text-decoration:none; font-weight:600;">Prenota un appuntamento</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d6deef;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/pudo-rOeNRrX3wtTc7XDpsj8AILJk9SKzZ3.png" alt="Punto ritiro pacchi" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:18px 24px;">
                                                    <p style="margin:0 0 8px; font-size:13px; color:#244b86; letter-spacing:0.1em; text-transform:uppercase;">Punto di ritiro pacchi</p>
                                                    <h2 style="margin:0 0 12px; font-size:21px; color:#1a2742;">Fermopoint BRT, GLS Shop, FedEx Location</h2>
                                                    <p style="margin:0 0 14px; font-size:15px; line-height:1.7; color:#3a4c69;">QRCode alla mano e ritiro espresso. Gestiamo anche resi marketplace e deposito temporaneo fino a 7 giorni lavorativi.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/punto-ritiro" style="display:inline-block; padding:10px 20px; border-radius:999px; border:2px solid #244b86; color:#244b86; text-decoration:none; font-weight:600;">Verifica gli orari</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; border:1px solid #d6deef;">
                                            <tr>
                                                <td width="200" style="padding:16px;">
                                                    <img src="https://qwyk4zaydta0yrkb.public.blob.vercel-storage.com/esempio-visura-camerale-YPfUuu8a7dXkH38cd4UcSSJNNvDPMX.jpg" alt="Visure" width="168" style="border-radius:12px; display:block; width:168px; height:auto;">
                                                </td>
                                                <td style="padding:18px 24px;">
                                                    <p style="margin:0 0 8px; font-size:13px; color:#244b86; letter-spacing:0.1em; text-transform:uppercase;">Visure e certificati</p>
                                                    <h2 style="margin:0 0 12px; font-size:21px; color:#1a2742;">Catastali, camerali, CRIF e protesti in tempo reale</h2>
                                                    <p style="margin:0 0 14px; font-size:15px; line-height:1.7; color:#3a4c69;">Erogazione immediata con firma digitale e invio del PDF certificato direttamente alla tua email di riferimento.</p>
                                                    <a href="https://www.agenziaplinio.it/servizi/visure" style="display:inline-block; padding:10px 20px; border-radius:999px; border:2px solid #244b86; color:#244b86; text-decoration:none; font-weight:600;">Richiedi una visura</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:14px; background-color:#f7f9ff; border:1px dashed #b8c6e3;">
                                <tr>
                                    <td style="padding:24px;">
                                        <h3 style="margin:0 0 10px; font-size:18px; color:#244b86;">Quando puoi trovarci</h3>
                                        <p style="margin:0 0 8px; font-size:15px; line-height:1.6; color:#3a4c69;">Lun-Ven: 9:00-13:20, 16:00-19:20 &middot; Sabato: 9:00-13:00</p>
                                        <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#3a4c69;">Siamo nel cuore di Castellammare di Stabia, a due passi da Piazza Spartaco.</p>
                                        <a href="https://www.agenziaplinio.it/dove-siamo" style="display:inline-block; padding:12px 26px; border-radius:999px; background-color:#244b86; color:#ffffff; text-decoration:none; font-weight:600;">Apri la mappa</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #d6deef;">
                                <tr>
                                    <td style="padding:22px 0 0; font-size:14px; line-height:1.6; color:#3a4c69;">
                                        <strong>AG Servizi Via Plinio 72</strong><br>
                                        Via Plinio il Vecchio 72, Castellammare di Stabia (NA)<br>
                                        Tel. +39 081 0584542 &middot; info@agenziaplinio.it
                                    </td>
                                    <td style="padding:22px 0 0; text-align:right;">
                                        <a href="https://www.facebook.com/agserviziplinio.it" style="display:inline-block; margin-left:8px; color:#244b86; text-decoration:none; font-weight:600;">Facebook</a>
                                        <a href="https://www.instagram.com/agenziaplinio" style="display:inline-block; margin-left:8px; color:#244b86; text-decoration:none; font-weight:600;">Instagram</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px 34px; text-align:center; font-size:12px; line-height:1.6; color:#6c7d93;">
                            Hai ricevuto questa email perch&eacute; sei cliente AG Servizi. <a href="{{unsubscribe_url}}" style="color:#244b86; text-decoration:underline;">Disiscriviti</a> se preferisci non ricevere pi&ugrave; aggiornamenti.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML,
    ],
];

if (!function_exists('email_templates_column_exists')) {
    function email_templates_column_exists(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $statement = $pdo->query("SHOW COLUMNS FROM email_templates LIKE '" . str_replace("'", "''", $column) . "'");
            $cache[$column] = $statement && $statement->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (PDOException $exception) {
            error_log('Email template column check failed: ' . $exception->getMessage());
            $cache[$column] = false;
        }

        return $cache[$column];
    }
}

if (!function_exists('email_marketing_tables_ready')) {
    function email_marketing_tables_ready(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        try {
            $pdo->query('SELECT 1 FROM email_templates LIMIT 1');
            $cache = true;
        } catch (PDOException $exception) {
            error_log('Email marketing tables missing? ' . $exception->getMessage());
            $cache = false;
        }

        return $cache;
    }
}

$csrfToken = csrf_token();
$emailTablesReady = email_marketing_tables_ready($pdo);
$errors = [];
$editingTemplate = null;

if ($emailTablesReady && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['template_id'])) {
    $templateId = (int) $_GET['template_id'];
    if ($templateId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = :id');
        $stmt->execute([':id' => $templateId]);
        $editingTemplate = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if ($emailTablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($csrfToken, $_POST['_token'] ?? '')) {
        $errors[] = 'Sessione scaduta, ricarica la pagina.';
    }

    if (!$errors && $action === 'save-template') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $preheader = trim($_POST['preheader'] ?? '');
        $html = trim($_POST['html'] ?? '');
        $supportsUpdatedAt = email_templates_column_exists($pdo, 'updated_at');
        $supportsCreatedBy = email_templates_column_exists($pdo, 'created_by');

        if ($name === '' || $subject === '') {
            $errors[] = 'Nome e oggetto sono obbligatori.';
        }
        if ($html === '') {
            $errors[] = 'Inserisci il contenuto HTML del template.';
        }

        if (!$errors) {
            try {
                if ($templateId > 0) {
                    $updateSql = 'UPDATE email_templates SET
                        name = :name,
                        subject = :subject,
                        preheader = :preheader,
                        html = :html';
                    if ($supportsUpdatedAt) {
                        $updateSql .= ', updated_at = NOW()';
                    }
                    $updateSql .= ' WHERE id = :id';

                    $stmt = $pdo->prepare($updateSql);
                    $stmt->execute([
                        ':name' => $name,
                        ':subject' => $subject,
                        ':preheader' => $preheader !== '' ? $preheader : null,
                        ':html' => $html,
                        ':id' => $templateId,
                    ]);
                    add_flash('success', 'Template aggiornato correttamente.');
                } else {
                    $columns = ['name', 'subject', 'preheader', 'html'];
                    $placeholders = [':name', ':subject', ':preheader', ':html'];
                    $parameters = [
                        ':name' => $name,
                        ':subject' => $subject,
                        ':preheader' => $preheader !== '' ? $preheader : null,
                        ':html' => $html,
                    ];

                    if ($supportsCreatedBy) {
                        $columns[] = 'created_by';
                        $placeholders[] = ':created_by';
                        $parameters[':created_by'] = (int) ($_SESSION['user_id'] ?? 0) ?: null;
                    }

                    $insertSql = sprintf('INSERT INTO email_templates (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));
                    $stmt = $pdo->prepare($insertSql);
                    $stmt->execute($parameters);
                    add_flash('success', 'Template creato.');
                }

                header('Location: templates.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Template save failed: ' . $exception->getMessage());
                $errors[] = 'Errore durante il salvataggio del template.';
            }
        }
    }

    if (!$errors && $action === 'delete-template') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM email_templates WHERE id = :id');
                $stmt->execute([':id' => $templateId]);
                add_flash('success', 'Template eliminato.');
                header('Location: templates.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Template delete failed: ' . $exception->getMessage());
                $errors[] = 'Impossibile eliminare il template. Verifica che non sia utilizzato da campagne attive.';
            }
        }
    }
}

$templates = [];
if ($emailTablesReady) {
    try {
        $hasCreatedBy = email_templates_column_exists($pdo, 'created_by');
        $hasUpdatedAt = email_templates_column_exists($pdo, 'updated_at');
        $hasCreatedAt = email_templates_column_exists($pdo, 'created_at');

        $select = 'SELECT t.id, t.name, t.subject, t.preheader, t.html';
        if ($hasCreatedBy) {
            $select .= ', t.created_by';
        }
        if ($hasUpdatedAt) {
            $select .= ', t.updated_at';
        }
        if ($hasCreatedAt) {
            $select .= ', t.created_at';
        }
        if ($hasCreatedBy) {
            $select .= ', u.username AS author_username';
        } else {
            $select .= ', NULL AS author_username';
        }

        $query = $select . ' FROM email_templates t';
        if ($hasCreatedBy) {
            $query .= ' LEFT JOIN users u ON u.id = t.created_by';
        }

        $orderColumn = $hasUpdatedAt ? 't.updated_at' : ($hasCreatedAt ? 't.created_at' : 't.id');
        $query .= ' ORDER BY ' . $orderColumn . ' DESC';

        $stmt = $pdo->query($query);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $exception) {
        error_log('Template load failed: ' . $exception->getMessage());
        $errors[] = 'Impossibile caricare i template.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Template email marketing</h1>
                <p class="text-muted mb-0">Definisci layout riutilizzabili per le campagne.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <?php if (!$emailTablesReady): ?>
            <div class="alert alert-warning">
                Per utilizzare questa sezione assicurati di aver eseguito le migrazioni email marketing.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo sanitize_output($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($emailTablesReady): ?>
            <div class="row g-4">
                <div class="col-12 col-xl-6">
                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0">
                            <h5 class="card-title mb-0"><?php echo $editingTemplate ? 'Modifica template' : 'Nuovo template'; ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="save-template">
                                <input type="hidden" name="template_id" value="<?php echo $editingTemplate ? (int) $editingTemplate['id'] : 0; ?>">
                                <div class="col-12">
                                    <label class="form-label" for="name">Nome *</label>
                                    <input class="form-control" id="name" name="name" value="<?php echo sanitize_output($editingTemplate['name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="subject">Oggetto *</label>
                                    <input class="form-control" id="subject" name="subject" value="<?php echo sanitize_output($editingTemplate['subject'] ?? ''); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="preheader">Preheader</label>
                                    <input class="form-control" id="preheader" name="preheader" value="<?php echo sanitize_output($editingTemplate['preheader'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="html">HTML *</label>
                                    <textarea class="form-control font-monospace" id="html" name="html" rows="12" required><?php echo sanitize_output($editingTemplate['html'] ?? "<h1>Ciao {{first_name}}</h1>\n<p>Benvenuto nella nostra newsletter.</p>\n<p><a href='{{unsubscribe_url}}'>Disiscriviti</a></p>"); ?></textarea>
                                    <small class="text-muted">Variabili disponibili: <code>{{ first_name }}</code>, <code>{{ last_name }}</code>, <code>{{ unsubscribe_url }}</code>.</small>
                                </div>
                                <div class="col-12 text-end">
                                    <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i><?php echo $editingTemplate ? 'Aggiorna template' : 'Crea template'; ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php if ($presetTemplates): ?>
                        <div class="card ag-card mt-4">
                            <div class="card-header bg-transparent border-0">
                                <h5 class="card-title mb-0">Modelli preimpostati AG Servizi</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">Seleziona un modello per compilare automaticamente nome, oggetto, preheader e HTML.</p>
                                <?php foreach ($presetTemplates as $preset): ?>
                                    <?php $presetId = 'preset-html-' . preg_replace('/[^a-z0-9\-]/i', '', (string) $preset['id']); ?>
                                    <div class="border rounded-3 p-3 p-md-4 mb-3">
                                        <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?php echo sanitize_output($preset['name']); ?></h6>
                                                <div class="text-muted small">Oggetto: <span class="fw-semibold text-dark"><?php echo sanitize_output($preset['subject']); ?></span></div>
                                                <div class="text-muted small">Preheader: <span class="fw-semibold text-dark"><?php echo sanitize_output($preset['preheader']); ?></span></div>
                                            </div>
                                            <div class="text-lg-end">
                                                <button type="button"
                                                    class="btn btn-outline-primary"
                                                    data-template-name="<?php echo htmlspecialchars($preset['name'], ENT_QUOTES); ?>"
                                                    data-template-subject="<?php echo htmlspecialchars($preset['subject'], ENT_QUOTES); ?>"
                                                    data-template-preheader="<?php echo htmlspecialchars($preset['preheader'], ENT_QUOTES); ?>"
                                                    data-template-html-source="<?php echo htmlspecialchars($presetId, ENT_QUOTES); ?>">
                                                    <i class="fa-solid fa-paintbrush me-2"></i>Usa questo modello
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <textarea id="<?php echo htmlspecialchars($presetId, ENT_QUOTES); ?>" class="d-none" aria-hidden="true"><?php echo htmlspecialchars($preset['html'], ENT_QUOTES); ?></textarea>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Template disponibili</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Oggetto</th>
                                            <th>Ultima modifica</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($templates): ?>
                                            <?php foreach ($templates as $template): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo sanitize_output($template['name']); ?></div>
                                                        <small class="text-muted">Creato da <?php echo sanitize_output($template['author_username'] ?? 'Sistema'); ?></small>
                                                    </td>
                                                    <td><?php echo sanitize_output($template['subject']); ?></td>
                                                    <td>
                                                        <?php
                                                        $lastUpdated = $template['updated_at'] ?? $template['created_at'] ?? null;
                                                        if ($lastUpdated) {
                                                            echo sanitize_output(format_datetime($lastUpdated));
                                                        } else {
                                                            echo '<span class="text-muted">N/D</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <a class="btn btn-sm btn-outline-warning" href="templates.php?template_id=<?php echo (int) $template['id']; ?>"><i class="fa-solid fa-pen-to-square"></i></a>
                                                        <form method="post" class="d-inline ms-2" onsubmit="return confirm('Eliminare questo template?');">
                                                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="delete-template">
                                                            <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">Nessun template creato.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($emailTablesReady && $presetTemplates): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var buttons = document.querySelectorAll('[data-template-html-source]');
                if (!buttons.length) {
                    return;
                }
                buttons.forEach(function (button) {
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        if (!confirm('Caricare questo modello? I campi correnti verranno sovrascritti.')) {
                            return;
                        }
                        var nameField = document.getElementById('name');
                        var subjectField = document.getElementById('subject');
                        var preheaderField = document.getElementById('preheader');
                        var htmlField = document.getElementById('html');
                        var sourceId = button.getAttribute('data-template-html-source');
                        var source = sourceId ? document.getElementById(sourceId) : null;
                        if (nameField) {
                            nameField.value = button.getAttribute('data-template-name') || '';
                        }
                        if (subjectField) {
                            subjectField.value = button.getAttribute('data-template-subject') || '';
                        }
                        if (preheaderField) {
                            preheaderField.value = button.getAttribute('data-template-preheader') || '';
                        }
                        if (htmlField && source) {
                            htmlField.value = (source.value || '').trim();
                            htmlField.dispatchEvent(new Event('input'));
                        }
                    });
                });
            });
        </script>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
