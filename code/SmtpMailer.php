<?php


require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'class.phpmailer.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'class.smtp.php');


class SmtpMailer extends Mailer {
	var $mailer = null;

	private $sendDelay = 0;


	function __construct($mailer = null){
		parent::__construct();
		$this->mailer = $mailer;
	}


	protected function instanciate(){
		//due to throttling on some services (i.e. AWS SES) we should make the sender pause
		$this->sendDelay = defined('SMTPMAILER_SEND_DELAY') ? SMTPMAILER_SEND_DELAY : 0;

		if($this->mailer == null){
			$this->mailer = new PHPMailer(true);
			$this->mailer->IsSMTP();
			$this->mailer->CharSet = defined('SMTPMAILER_CHARSET_ENCODING') ? SMTPMAILER_CHARSET_ENCODING : "utf-8";
			$this->mailer->Host = defined('SMTPMAILER_SMTP_SERVER_ADDRESS') ? SMTPMAILER_SMTP_SERVER_ADDRESS : "localhost";
			$this->mailer->Port = defined('SMTPMAILER_SMTP_SERVER_PORT') ? SMTPMAILER_SMTP_SERVER_PORT : 25;
			$this->mailer->SMTPSecure = defined('SMTPMAILER_USE_SECURE_CONNECTION') ? strtolower(SMTPMAILER_USE_SECURE_CONNECTION) : '';
			$this->mailer->SMTPAuth = defined('SMTPMAILER_DO_AUTHENTICATE') ? SMTPMAILER_DO_AUTHENTICATE : false;
			if($this->mailer->SMTPAuth){
				$this->mailer->Username = defined('SMTPMAILER_USERNAME') ? SMTPMAILER_USERNAME : "username";
				$this->mailer->Password = defined('SMTPMAILER_PASSWORD') ? SMTPMAILER_PASSWORD : "password";
			}
			$this->mailer->SMTPDebug = defined('SMTPMAILER_DEBUG_MESSAGING_LEVEL') ? SMTPMAILER_DEBUG_MESSAGING_LEVEL : 0;
			$this->mailer->SetLanguage(defined('SMTPMAILER_LANGUAGE_OF_MESSAGES') ? SMTPMAILER_LANGUAGE_OF_MESSAGES : 'en');
			$this->mailer->ErrorLevel = defined('SMTPMAILER_SMTP_ERROR_LEVEL') ? SMTPMAILER_SMTP_ERROR_LEVEL : E_USER_ERROR;
		}
	}


	/* Overwriting SilverStripe's Mailer function */
	function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customheaders = false){
		$this->instanciate();
		$this->mailer->IsHTML(false);
		$this->mailer->Body = $plainContent;
		$this->sendMailViaSmtp($to, $from, $subject, $attachedFiles, $customheaders, false);
	}


	/* Overwriting SilverStripe's Mailer's function */
	function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false, $inlineImages = false){
		$this->instanciate();
		$this->mailer->IsHTML(true);
		if($inlineImages){
			$this->mailer->MsgHTML($htmlContent, Director::baseFolder());
		} else {
			$this->mailer->Body = $htmlContent;
			if(empty($plainContent)){
				$plainContent = trim(Convert::html2raw($htmlContent));
			}
			$this->mailer->AltBody = $plainContent;
		}
		return $this->sendMailViaSmtp($to, $from, $subject, $attachedFiles, $customheaders, $inlineImages);
	}


	protected function sendMailViaSmtp($to, $from, $subject, $attachedFiles = false, $customheaders = false, $inlineImages = false){
		// setting a blank $from address should try to use the site's default administrator email
		if(empty($from)) $from = Email::config()->admin_email;

		if($this->mailer->SMTPDebug > 0){
			echo "<em><strong>*** Debug mode is on</strong>, printing debug messages and not redirecting to the website:</em><br/>";
			echo "To: $to, From: $from, Subject: $subject<br>";
		}
		$msgForLog = "\n*** The sender was : $from\n*** The message was :\n{$this->mailer->AltBody}\n";

		try {
			$this->buildBasicMail($to, $from, $subject);
			$customheaders['X-SMTPAPI'] = '{"category": "' . $_SERVER['HTTP_HOST'] . '"}'; // Add the current domain for services like SendGrid
			$this->addCustomHeaders($customheaders);
			$this->attachFiles($attachedFiles);

			//Due to AWS SES, sometimes we need to throttle out e-mail delivery
			if($this->sendDelay > 0){
				usleep($this->sendDelay * 1000);//we want milliseconds, not microseconds
			}

			$this->mailer->Send();

			if($this->mailer->SMTPDebug > 0){
				echo "<em><strong>*** E-mail to $to has been sent.</strong></em><br />";
				echo "<em><strong>*** The debug mode blocked the process</strong> to avoid the url redirection. So the CC e-mail is not sent.</em>";
				die();
			}

			return true; // If everything worked.

		} catch(phpmailerException $pe){
			$this->handleError($pe->errorMessage(), $msgForLog);
		} catch(Exception $e){
			$this->handleError($e->getMessage(), $msgForLog);
		}
	}


	function handleError($msg, $msgForLog){
		// $msgForLog contains whole body of email also
		SS_Log::log('SMTP Mailer: error occured with exception: '.$msg."\nEmail content: ".$msgForLog, SS_Log::WARN);
		throw new Exception('SMTP Mailer: error occured with exception: '.$msg."\nEmail content: ".$msgForLog);
		// do not end up in error
		// user_error($msg, $this->mailer->ErrorLevel);
	}


	protected function buildBasicMail($to, $from, $subject){
		if(preg_match('/(\'|")(.*?)\1[ ]+<[ ]*(.*?)[ ]*>/', $from, $from_splitted)){ //If $from countains a name, e.g. "My Name" <foo@gmail.com>
			$this->mailer->SetFrom($from_splitted[3], $from_splitted[2]);
		} else {
			$this->mailer->SetFrom($from);
		}

		$this->mailer->ClearAddresses();
		// loop through addressees if more are set
		foreach (explode(',', $to) as $recipient) {
			$recipient = trim($recipient);
			if (strlen($recipient) > 0) {
				if(preg_match('/(\'|")(.*?)\1[ ]+<[ ]*(.*?)[ ]*>/', $recipient, $recipient_splitted)){ //If $to countains a name, e.g. "My Name" <foo@gmail.com>
					$this->mailer->AddAddress($recipient_splitted[3], $recipient_splitted[2]);
				} else {
					//For the recipient's name, the string before the @ from the e-mail address is used
					$this->mailer->AddAddress($recipient, ucfirst(substr($recipient, 0, strpos($recipient, '@'))));
				}
			}
		}

		$this->mailer->Subject = $subject;
	}


	protected function addCustomHeaders($headers){
		if(($headers == null) || !is_array($headers)){
			$headers = array();
		}
		if(!isset($headers["X-Mailer"])){
			$headers["X-Mailer"] = X_MAILER;
		}
		if(!isset($headers["X-Priority"])){
			$headers["X-Priority"] = 3;
		}

		$this->mailer->ClearCustomHeaders();

		//Convert cc/bcc/ReplyTo from headers to properties
		foreach($headers as $header_name => $header_value){
			if(in_array(strtolower($header_name), array('cc', 'bcc', 'reply-to', 'replyto'))){
				$addresses = preg_split('/(,|;)/', $header_value);
			}
			switch(strtolower($header_name)) {
				case 'cc':
					foreach($addresses as $address){ $this->mailer->addCC($address); }
					break;
				case 'bcc':
					foreach($addresses as $address) { $this->mailer->addBCC($address); }
					break;
				case 'reply-to':
					foreach($addresses as $address) { $this->mailer->addReplyTo($address); }
					break;
				default:
					$this->mailer->AddCustomHeader($header_name . ':' . $header_value);
					break;
			}
		}
	}


	protected function attachFiles($attachedFiles){
		// add attachments
		if ($attachedFiles && is_array($attachedFiles)) foreach ($attachedFiles as $attachedFile) {
			$this->mailer->addStringAttachment($attachedFile['contents'], $attachedFile['filename']);
		}
		/*if(!empty($attachedFiles) && is_array($attachedFiles)){
			foreach($attachedFiles as $attachedFile){
				if(substr($attachedFile['filename'], 0, strlen(Director::baseFolder())) === Director::baseFolder()){ // If the file path is already included, don't include it again
					$filePath = $attachedFile['filename'];
				} else {
					$filePath = Director::baseFolder() . DIRECTORY_SEPARATOR . $attachedFile['filename'];
				}
				$this->mailer->AddAttachment($filePath);
			}
		}*/
	}


}
