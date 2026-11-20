<?php
declare(strict_types=1);

namespace ServicioSocial\Servicios\Correo;

use ServicioSocial\Nucleo\Config;

/**
 * Envío real por el SMTP institucional con PHPMailer (instalado con
 * Composer: composer require phpmailer/phpmailer).
 */
final class CorreoSmtp implements Correo
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enviar(string $para, string $asunto, string $texto): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new \RuntimeException('Falta PHPMailer: ejecuta composer install y sube la carpeta vendor.');
        }
        $correo = new \PHPMailer\PHPMailer\PHPMailer(true);
        $correo->isSMTP();
        $correo->Host       = (string)$this->config->get('correo.smtp_host');
        $correo->Port       = (int)$this->config->get('correo.smtp_puerto', 587);
        $correo->SMTPAuth   = true;
        $correo->Username   = (string)$this->config->get('correo.smtp_usuario');
        $correo->Password   = (string)$this->config->get('correo.smtp_password');
        $correo->SMTPSecure = $correo->Port === 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $correo->CharSet    = 'UTF-8';
        $correo->setFrom((string)$this->config->get('correo.remitente'), (string)$this->config->get('correo.nombre', 'Servicio Social'));
        $correo->addAddress($para);
        $correo->Subject = $asunto;
        $correo->Body    = $texto;
        $correo->isHTML(false);
        $correo->send();
    }
}
