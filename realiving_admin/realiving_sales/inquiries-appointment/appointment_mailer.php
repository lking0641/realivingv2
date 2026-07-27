<?php
// appointment_mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require ROOT_PATH . 'vendor/autoload.php';
include $includes ['connection'];

if (!defined('MAILER_INCLUDED_ONLY')) {
    session_start();
    include $includes ['checkrole'];
    require_role(['sales', 'superadmin']);
}

function sendAppointmentEmail($appointment, $recipient_email, $email_type = 'confirmation') {
    $mail = new PHPMailer(true);
    try {
        // SMTP config
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'realivingwebsite@gmail.com';
        $mail->Password = 'foudsaptlzlwbvst';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Sender & Recipient
        $mail->setFrom('realivingwebsite@gmail.com', 'Realiving');
        $mail->addAddress($recipient_email);
        $mail->isHTML(true);
        $mail->addEmbeddedImage(ROOT_PATH . 'logo/mmone.png', 'realivinglogo');

        // Determine email content based on type
        switch ($email_type) {
            case 'confirmation':
                $mail->Subject = 'Appointment Confirmation - Realiving';
                $mail->Body = getConfirmationEmailBody($appointment);
                break;
            case 'converted':
                $mail->Subject = 'Welcome to Realiving - Client Account Created';
                $mail->Body = getConversionEmailBody($appointment);
                break;
            case 'schedule_confirmed':
                $mail->Subject = 'Your Appointment Schedule is Confirmed';
                $mail->Body = getScheduleConfirmationBody($appointment);
                break;
            case 'rescheduled':
                $mail->Subject = 'Your Appointment Has Been Rescheduled - Realiving';
                $mail->Body = getRescheduleEmailBody($appointment);
                break;
        }

        $mail->send();
        return true;

    } catch (Exception $e) {
        return "Mailer Error: " . $mail->ErrorInfo;
    }
}

function getConfirmationEmailBody($appointment) {
    $service_display = $appointment['service_type'];
    if ($appointment['service_type'] === 'Other' && !empty($appointment['other_service'])) {
        $service_display .= ' - ' . htmlspecialchars($appointment['other_service']);
    }

    return '
    <div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px; border-radius: 10px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="cid:realivinglogo" alt="Realiving Logo" style="width: 120px; height: auto;">
        </div>
        <div style="background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h2 style="color: #3b1f0f; text-align: center; margin-bottom: 20px;">Thank You for Your Appointment Request!</h2>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">Dear ' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . ',</p>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                Thank you for scheduling an appointment with Realiving. We have received your request and our team will review it shortly.
            </p>

            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3b1f0f;">
                <h3 style="color: #3b1f0f; margin-top: 0;">Appointment Details:</h3>
                <table style="width: 100%; color: #333;">
                    <tr>
                        <td style="padding: 8px 0;"><strong>Service Type:</strong></td>
                        <td style="padding: 8px 0;">' . $service_display . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Preferred Date:</strong></td>
                        <td style="padding: 8px 0;">' . date('F j, Y', strtotime($appointment['preferred_date'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Preferred Time:</strong></td>
                        <td style="padding: 8px 0;">' . date('g:i A', strtotime($appointment['preferred_time'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Contact:</strong></td>
                        <td style="padding: 8px 0;">' . htmlspecialchars($appointment['country_code'] . ' ' . $appointment['phone']) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Status:</strong></td>
                        <td style="padding: 8px 0;"><span style="background-color: #ffc107; color: #000; padding: 4px 12px; border-radius: 4px; font-weight: bold;">PENDING</span></td>
                    </tr>
                </table>
            </div>

            ' . (!empty($appointment['notes']) ? '
            <div style="background-color: #fff9e6; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h4 style="color: #856404; margin-top: 0;">Your Notes:</h4>
                <p style="color: #856404; margin: 0;">' . nl2br(htmlspecialchars($appointment['notes'])) . '</p>
            </div>' : '') . '

            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                Our team will contact you soon to confirm your appointment. If you need to make any changes or have questions, 
                please don\'t hesitate to reach out to us.
            </p>

            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <p style="color: #666; font-size: 14px;">Contact us:</p>
                <p style="color: #3b1f0f; font-size: 14px; margin: 5px 0;">
                    <strong>Email:</strong> realivingdesign.corp@gmail.com<br>
                    <strong>Phone:</strong> +63 985 124 5929
                </p>
            </div>
        </div>
        <p style="text-align: center; color: #999; font-size: 12px; margin-top: 20px;">
            This is an automated message from Realiving. Please do not reply to this email.
        </p>
    </div>';
}

function getConversionEmailBody($appointment) {
    return '
    <div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px; border-radius: 10px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="cid:realivinglogo" alt="Realiving Logo" style="width: 120px; height: auto;">
        </div>
        <div style="background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h2 style="color: #28a745; text-align: center; margin-bottom: 20px;">🎉 Welcome to Realiving!</h2>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">Dear ' . htmlspecialchars($appointment['clientname']) . ',</p>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                Congratulations! Your consultation appointment has been successfully converted to a client account. 
                We\'re excited to begin working with you on your project.
            </p>

            <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                <h3 style="color: #155724; margin-top: 0;">Your Client Information:</h3>
                <table style="width: 100%; color: #155724;">
                    <tr>
                        <td style="padding: 8px 0;"><strong>Reference Number:</strong></td>
                        <td style="padding: 8px 0; font-family: monospace; font-size: 14px;">' . htmlspecialchars($appointment['reference_number']) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Project Name:</strong></td>
                        <td style="padding: 8px 0;">' . htmlspecialchars($appointment['nameproject']) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Client Status:</strong></td>
                        <td style="padding: 8px 0;">' . htmlspecialchars($appointment['status']) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Account Created:</strong></td>
                        <td style="padding: 8px 0;">' . date('F j, Y') . '</td>
                    </tr>
                </table>
            </div>

            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #3b1f0f; margin-top: 0;">What\'s Next?</h3>
                <ul style="color: #333; line-height: 1.8;">
                    <li>Your dedicated account manager will contact you within 24 hours</li>
                    <li>We\'ll schedule a detailed project discussion</li>
                    <li>You\'ll receive a comprehensive project proposal</li>
                    <li>We\'ll begin planning your project timeline</li>
                </ul>
            </div>

            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                If you have any questions or need immediate assistance, please don\'t hesitate to contact us using your 
                reference number: <strong style="color: #3b1f0f;">' . htmlspecialchars($appointment['reference_number']) . '</strong>
            </p>

            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <p style="color: #666; font-size: 14px;">Contact us:</p>
                <p style="color: #3b1f0f; font-size: 14px; margin: 5px 0;">
                    <strong>Email:</strong> realivingdesign.corp@gmail.com<br>
                    <strong>Phone:</strong> +63 985 124 5929
                </p>
            </div>
        </div>
        <p style="text-align: center; color: #999; font-size: 12px; margin-top: 20px;">
            This is an automated message from Realiving. Please do not reply to this email.
        </p>
    </div>';
}

function getRescheduleEmailBody($appointment) {
    $service_display = $appointment['service_type'];
    if ($appointment['service_type'] === 'Other' && !empty($appointment['other_service'])) {
        $service_display .= ' - ' . htmlspecialchars($appointment['other_service']);
    }

    return '
    <div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px; border-radius: 10px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="cid:realivinglogo" alt="Realiving Logo" style="width: 120px; height: auto;">
        </div>
        <div style="background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h2 style="color: #d97706; text-align: center; margin-bottom: 20px;">📅 Your Appointment Has Been Rescheduled</h2>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">Dear ' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . ',</p>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                We would like to inform you that your appointment with Realiving has been <strong>rescheduled</strong>. 
                Please take note of your new appointment details below.
            </p>

            <div style="background-color: #fff7ed; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #d97706;">
                <h3 style="color: #92400e; margin-top: 0;">New Appointment Schedule:</h3>
                <table style="width: 100%; color: #92400e;">
                    <tr>
                        <td style="padding: 8px 0;"><strong>Service:</strong></td>
                        <td style="padding: 8px 0;">' . $service_display . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>New Date:</strong></td>
                        <td style="padding: 8px 0; font-size: 18px; font-weight: bold;">' . date('F j, Y', strtotime($appointment['preferred_date'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>New Time:</strong></td>
                        <td style="padding: 8px 0; font-size: 18px; font-weight: bold;">' . date('g:i A', strtotime($appointment['preferred_time'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Status:</strong></td>
                        <td style="padding: 8px 0;"><span style="background-color: #d97706; color: white; padding: 4px 12px; border-radius: 4px; font-weight: bold;">RESCHEDULED</span></td>
                    </tr>
                </table>
            </div>

            <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;">📋 Please Remember:</h3>
                <ul style="color: #856404; line-height: 1.8;">
                    <li>Please arrive 5-10 minutes early</li>
                    <li>Bring any relevant documents or plans</li>
                    <li>If you need to reschedule again, contact us at least 24 hours in advance</li>
                    <li>Save this email for your reference</li>
                </ul>
            </div>

            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                We apologize for any inconvenience this may have caused. If you have any questions or concerns, 
                please do not hesitate to contact us.
            </p>

            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <p style="color: #666; font-size: 14px;">Contact us:</p>
                <p style="color: #3b1f0f; font-size: 14px; margin: 5px 0;">
                    <strong>Email:</strong> realivingdesign.corp@gmail.com<br>
                    <strong>Phone:</strong> +63 985 124 5929
                </p>
            </div>
        </div>
        <p style="text-align: center; color: #999; font-size: 12px; margin-top: 20px;">
            This is an automated message from Realiving. Please do not reply to this email.
        </p>
    </div>';
}

function getScheduleConfirmationBody($appointment) {
    $service_display = $appointment['service_type'];
    if ($appointment['service_type'] === 'Other' && !empty($appointment['other_service'])) {
        $service_display .= ' - ' . htmlspecialchars($appointment['other_service']);
    }

    return '
    <div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px; border-radius: 10px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="cid:realivinglogo" alt="Realiving Logo" style="width: 120px; height: auto;">
        </div>
        <div style="background-color: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h2 style="color: #28a745; text-align: center; margin-bottom: 20px;">✅ Your Appointment is Confirmed!</h2>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">Dear ' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . ',</p>
            
            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                Great news! Your appointment with Realiving has been confirmed. We look forward to meeting with you.
            </p>

            <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                <h3 style="color: #155724; margin-top: 0;">Confirmed Appointment Details:</h3>
                <table style="width: 100%; color: #155724;">
                    <tr>
                        <td style="padding: 8px 0;"><strong>Service:</strong></td>
                        <td style="padding: 8px 0;">' . $service_display . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Date:</strong></td>
                        <td style="padding: 8px 0; font-size: 18px; font-weight: bold;">' . date('F j, Y', strtotime($appointment['preferred_date'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Time:</strong></td>
                        <td style="padding: 8px 0; font-size: 18px; font-weight: bold;">' . date('g:i A', strtotime($appointment['preferred_time'])) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0;"><strong>Status:</strong></td>
                        <td style="padding: 8px 0;"><span style="background-color: #28a745; color: white; padding: 4px 12px; border-radius: 4px; font-weight: bold;">CONFIRMED</span></td>
                    </tr>
                </table>
            </div>

            <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;">📋 Please Remember:</h3>
                <ul style="color: #856404; line-height: 1.8;">
                    <li>Please arrive 5-10 minutes early</li>
                    <li>Bring any relevant documents or plans</li>
                    <li>If you need to reschedule, contact us at least 24 hours in advance</li>
                    <li>Save this email for your reference</li>
                </ul>
            </div>

            <p style="color: #333; font-size: 16px; line-height: 1.6;">
                If you have any questions or need to make changes to your appointment, please contact us as soon as possible.
            </p>

            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <p style="color: #666; font-size: 14px;">Contact us:</p>
                <p style="color: #3b1f0f; font-size: 14px; margin: 5px 0;">
                    <strong>Email:</strong> realivingdesign.corp@gmail.com<br>
                    <strong>Phone:</strong> +63 985 124 5929
                </p>
            </div>
        </div>
        <p style="text-align: center; color: #999; font-size: 12px; margin-top: 20px;">
            This is an automated message from Realiving. Please do not reply to this email.
        </p>
    </div>';
}

// ============ POST HANDLER ============
if (!defined('MAILER_INCLUDED_ONLY') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_id = intval($_POST['appointment_id']);
    $email_type = $_POST['email_type'] ?? 'confirmation';

    // Fetch appointment details
    if ($email_type === 'converted') {
        // For converted clients, fetch from user_info table
        $stmt = $conn->prepare("SELECT u.*, a.first_name, a.last_name, a.email as appointment_email, a.country_code, a.phone 
                                FROM user_info u 
                                INNER JOIN appointments a ON u.appointment_id_fk = a.appointment_id 
                                WHERE u.appointment_id_fk = ?");
    } else {
        // For regular appointments
        $stmt = $conn->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
    }
    
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();

    if (!$appointment) {
        $_SESSION['error_message'] = "Appointment not found.";
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
    }

    // Determine recipient email
    $recipient_email = ($email_type === 'converted') ? $appointment['email'] : $appointment['email'];

    // Send email
    $send_result = sendAppointmentEmail($appointment, $recipient_email, $email_type);

    if ($send_result === true) {
        // Update appointment status if schedule is confirmed
        if ($email_type === 'schedule_confirmed') {
            $conn->query("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = $appointment_id");
        }
        if ($email_type === 'rescheduled') {
            $conn->query("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = $appointment_id");
        }
        
        $_SESSION['success_message'] = 'Email sent successfully to ' . htmlspecialchars($recipient_email);
    } else {
        $_SESSION['error_message'] = $send_result;
    }

    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}
?>