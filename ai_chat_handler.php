<?php
header('Content-Type: application/json');

// Simple AI Response Handler for VictorianPass
// In a production environment, you would integrate with a real AI API like OpenAI, Cohere, or Hugging Face

session_start();

$response = [
    'success' => false,
    'message' => '',
    'response' => ''
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    $response['message'] = 'Query is required';
    echo json_encode($response);
    exit;
}

// Knowledge base of common questions and answers
$knowledgeBase = [
    // Registration Questions
    [
        'keywords' => ['register', 'registration', 'how do i register', 'sign up', 'create account'],
        'response' => 'To register as a resident in VictorianPass, click the "Register" button in the top navigation. Fill in your personal information including your full name, email, password, and house number. After submitting, you will receive a verification email. Click the link in the email to confirm your account. Once verified, you can log in and access all resident features.'
    ],
    // Visitor Pass Questions
    [
        'keywords' => ['visitor', 'visitor pass', 'request visitor', 'request a visitor pass', 'guest pass', 'entry pass'],
        'response' => 'To request a visitor pass, navigate to the "Reserve" section or click on your profile dropdown and select "Reserve an Amenity". You can submit visitor information including their name, identification type, and visit date. Your request will be reviewed by administration, and you\'ll receive updates on its status.'
    ],
    // Amenities Questions
    [
        'keywords' => ['amenities', 'amenity', 'facilities', 'what amenities', 'view amenities', 'what facilities'],
        'response' => 'Victorian Heights Subdivision offers excellent amenities including:\n• Clubhouse - Ideal for gatherings and community events\n• Multi-Purpose Building - A versatile space for various community activities\n• Basketball Court - For sports and fitness activities\n• Tennis Court - For recreational and competitive play\n\nAll amenities can be reserved through the VictorianPass system. Check the "Amenities" section on our homepage for more details.'
    ],
    // QR System Questions
    [
        'keywords' => ['qr', 'qr system', 'how does qr work', 'qr code', 'qr scanning', 'how does the qr visitor system work'],
        'response' => 'Our QR system provides fast and secure access control for the subdivision. When you reserve an amenity or request a visitor pass, a unique QR code is generated. Simply present this QR code at the security gate or amenity entrance. The system scans the code for verification and grants access. This streamlines entry and improves security for our community.'
    ],
    // Contact Questions
    [
        'keywords' => ['contact', 'contact administration', 'contact admin', 'how do i contact', 'support', 'help', 'reach out'],
        'response' => 'To contact the administration:\n• Email: admin@victorianpass.com\n• Phone: +63 (2) 1234-5678\n• Office Hours: Monday - Friday, 9:00 AM - 5:00 PM\n• You can also submit an incident report through the "Report Incident" section in your dashboard.'
    ],
    // Status Checking
    [
        'keywords' => ['check status', 'status', 'my status', 'application status', 'where is my'],
        'response' => 'To check the status of your visitor pass, amenity reservation, or incident report:\n1. Log in to your VictorianPass account\n2. Click on your profile icon in the top right\n3. Select "Open Full View of Profile Dashboard"\n4. Navigate to the appropriate section (Reservations, Visitor Passes, Reports) to view status updates\n\nStatus updates are also sent via email notification.'
    ],
    // Security & Access
    [
        'keywords' => ['security', 'access', 'entry', 'gate', 'secure'],
        'response' => 'Victorian Heights prioritizes security and community safety. Our QR-based access system ensures:\n• Controlled entry through the main gate\n• Real-time tracking of visitor access\n• Secure amenity reservations\n• Enhanced incident reporting and response\n\nAll residents and visitors must provide valid identification at the gate. Security personnel monitor access 24/7.'
    ],
    // Incident Reporting
    [
        'keywords' => ['report', 'incident', 'report incident', 'problem', 'issue', 'concern'],
        'response' => 'To report an incident in Victorian Heights:\n1. Log in to your VictorianPass account\n2. Go to "Report Incident" from the main menu\n3. Describe the incident with details (date, time, location)\n4. Upload supporting photos or documents if applicable\n5. Submit your report\n\nAdministration will review and respond to your report. You can track the status from your dashboard.'
    ],
    // Profile & Account
    [
        'keywords' => ['profile', 'account', 'edit profile', 'update profile', 'change password', 'password'],
        'response' => 'To manage your VictorianPass account:\n• Click your profile icon in the top navigation\n• Select "Open Full View of Profile Dashboard"\n• Update your personal information, email, or phone number\n• To change your password, use the "Change Password" option in your account settings\n• All changes require authentication for security purposes'
    ],
    // Reservation Questions
    [
        'keywords' => ['reserve', 'reservation', 'book', 'booking', 'reserve amenity'],
        'response' => 'To reserve an amenity at Victorian Heights:\n1. Log in to your account (residents) or submit a visitor pass request (guests)\n2. Click "Reserve an Amenity"\n3. Select the facility you wish to reserve\n4. Choose your preferred date and time\n5. Confirm your reservation\n\nYou\'ll receive a confirmation email with your QR code for entry at the scheduled time.'
    ],
    // About the System
    [
        'keywords' => ['about', 'about the system', 'what is', 'tell me about', 'how does it work'],
        'response' => 'VictorianPass is a modern subdivision management system designed to:\n• Streamline amenity reservations\n• Manage visitor access securely\n• Facilitate incident reporting\n• Provide real-time updates via QR technology\n\nOur system enhances community safety, improves convenience, and strengthens the sense of belonging in Victorian Heights Subdivision.'
    ],
    // General Information
    [
        'keywords' => ['victorian heights', 'subdivision', 'about us', 'information', 'location'],
        'response' => 'Victorian Heights Subdivision is a gated residential community located in Brgy. Sauyo, Quezon City. Developed by Swire Land Corporation, it features:\n• 222 residential homes\n• Approximately 2,220 residents\n• Complete security and gate-controlled access\n• Modern amenities (clubhouse, multi-purpose building, sports courts)\n• Professional community management\n\nOur community emphasizes safety, convenience, and a strong sense of belonging.'
    ],
    // FAQ Category
    [
        'keywords' => ['faq', 'frequently asked', 'common questions', 'help'],
        'response' => 'Popular questions about VictorianPass:\n1. How do I register? → Click Register and fill in your information\n2. How do I request a visitor pass? → Go to Reservations and submit visitor details\n3. What amenities are available? → Clubhouse, Multi-Purpose Building, Basketball Court, Tennis Court\n4. How does the QR system work? → Scan your QR code at entry points for secure access\n5. How do I contact administration? → Email: admin@victorianpass.com or call during office hours\n\nNeed more help? Our support team is ready to assist.'
    ]
];

// Function to find the best matching response
function findResponse($query, $knowledgeBase) {
    $queryLower = strtolower($query);
    $bestMatch = null;
    $bestScore = 0;

    foreach ($knowledgeBase as $item) {
        $score = 0;
        foreach ($item['keywords'] as $keyword) {
            if (stripos($queryLower, $keyword) !== false) {
                $score += 1;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $item;
        }
    }

    if ($bestMatch) {
        return $bestMatch['response'];
    }

    // Default response if no match found
    return 'Thank you for your question! Our VictorianPass system is designed to help you with amenity reservations, visitor access management, and incident reporting. For specific concerns, please contact our administration team at admin@victorianpass.com or call +63 (2) 1234-5678.';
}

// Get the response
$aiResponse = findResponse($query, $knowledgeBase);

$response['success'] = true;
$response['response'] = $aiResponse;

echo json_encode($response);
?>