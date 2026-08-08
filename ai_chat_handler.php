<?php
header('Content-Type: application/json');

// Simple AI Response Handler for VictorianPass
// In a production environment, you would integrate with a real AI API like OpenAI, Cohere, or Hugging Face

session_start();

$isResident = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident';

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

$ecoPointProgramSummary = '<strong>VHEcoPoint</strong> is the Smart Waste Segregation Station of Victorian Heights Subdivision integrated into VictorianPass. Residents scan their VictorianPass QR code at the station to authenticate their session and deposit recyclables. Points are credited automatically per kilogram: Plastic (PET Bottles <=1000ml) at 55 pts/kg, Aluminum Cans at 140 pts/kg, and Paper &amp; Cardboard at 30 pts/kg. <strong>1 point = Php 0.30 in amenity value.</strong> The weekly cap is 250 points and resets every Monday at 12:00 AM. The daily limit is 3 sessions and 100 points per day. The maximum balance is 3,000 points, and points expire after 24 months. Redemption rates are 300 points for 1 free hour at the Basketball Court or Tennis Court, 600 points for 1 free hour at the Clubhouse, and 750 points for 1 free hour at the Multi-Purpose Building. Partial discounts are not available. Full point redemption only.';

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
        'response' => '<strong>Victorian Heights Subdivision offers excellent amenities:</strong><br><ul><li>Clubhouse - Ideal for gatherings and community events</li><li>Multi-Purpose Building - A versatile space for various community activities</li><li>Basketball Court - For sports and fitness activities</li><li>Tennis Court - For recreational and competitive play</li></ul>All amenities can be reserved through the VictorianPass system. Check the "Amenities" section on our homepage for more details.'
    ],
    // QR System Questions
    [
        'keywords' => ['qr', 'qr system', 'how does qr work', 'qr code', 'qr scanning', 'how does the qr visitor system work'],
        'response' => 'Our QR system provides fast and secure access control for the subdivision. When you reserve an amenity or request a visitor pass, a unique QR code is generated. Simply present this QR code at the security gate or amenity entrance. The system scans the code for verification and grants access. This streamlines entry and improves security for our community.'
    ],
    // Contact Questions
    [
        'keywords' => ['contact', 'contact administration', 'contact admin', 'how do i contact', 'support', 'help', 'reach out'],
        'response' => '<strong>To contact the administration:</strong><br><ul><li><strong>Email:</strong> admin@victorianpass.com</li><li><strong>Phone:</strong> +63 (2) 1234-5678</li><li><strong>Office Hours:</strong> Monday - Friday, 9:00 AM - 5:00 PM</li><li>You can also submit an incident report through the "Report Incident" section in your dashboard.</li></ul>'
    ],
    // Status Checking
    [
        'keywords' => ['check status', 'status', 'my status', 'application status', 'where is my'],
        'response' => '<strong>To check the status of your visitor pass, amenity reservation, or incident report:</strong><br><ol><li>Log in to your VictorianPass account</li><li>Click on your profile icon in the top right</li><li>Select "Open Full View of Profile Dashboard"</li><li>Navigate to the appropriate section (Reservations, Visitor Passes, Reports) to view status updates</li></ol>Status updates are also sent via email notification.'
    ],
    // Security & Access
    [
        'keywords' => ['security', 'access', 'entry', 'gate', 'secure'],
        'response' => '<strong>Victorian Heights prioritizes security and community safety.</strong> Our QR-based access system ensures:<br><ul><li>Controlled entry through the main gate</li><li>Real-time tracking of visitor access</li><li>Secure amenity reservations</li><li>Enhanced incident reporting and response</li></ul>All residents and visitors must provide valid identification at the gate. Security personnel monitor access 24/7.'
    ],
    // Incident Reporting
    [
        'keywords' => ['report', 'incident', 'report incident', 'problem', 'issue', 'concern'],
        'response' => '<strong>To report an incident in Victorian Heights:</strong><br><ol><li>Log in to your VictorianPass account</li><li>Go to "Report Incident" from the main menu</li><li>Describe the incident with details (date, time, location)</li><li>Upload supporting photos or documents if applicable</li><li>Submit your report</li></ol>Administration will review and respond to your report. You can track the status from your dashboard.'
    ],
    // Profile & Account
    [
        'keywords' => ['profile', 'account', 'edit profile', 'update profile', 'change password', 'password'],
        'response' => '<strong>To manage your VictorianPass account:</strong><br><ul><li>Click your profile icon in the top navigation</li><li>Select "Open Full View of Profile Dashboard"</li><li>Update your personal information, email, or phone number</li><li>To change your password, use the "Change Password" option in your account settings</li><li>All changes require authentication for security purposes</li></ul>'
    ],
    // Reservation Questions
    [
        'keywords' => ['reserve', 'reservation', 'book', 'booking', 'reserve amenity'],
        'response' => '<strong>To reserve an amenity at Victorian Heights:</strong><br><ol><li>Log in to your account (residents) or submit a visitor pass request (guests)</li><li>Click "Reserve an Amenity"</li><li>Select the facility you wish to reserve</li><li>Choose your preferred date and time</li><li>Confirm your reservation</li></ol>You\'ll receive a confirmation email with your QR code for entry at the scheduled time.'
    ],
    // About the System
    [
        'keywords' => ['about', 'about the system', 'what is', 'tell me about', 'how does it work'],
        'response' => '<strong>VictorianPass is a modern subdivision management system designed to:</strong><br><ul><li>Streamline amenity reservations</li><li>Manage visitor access securely</li><li>Facilitate incident reporting</li><li>Provide real-time updates via QR technology</li></ul>Our system enhances community safety, improves convenience, and strengthens the sense of belonging in Victorian Heights Subdivision.'
    ],
    // General Information
    [
        'keywords' => ['victorian heights', 'subdivision', 'about us', 'information', 'location'],
        'response' => '<strong>Victorian Heights Subdivision</strong> is a gated residential community located in Brgy. Sauyo, Quezon City. Developed by Swire Land Corporation, it features:<br><ul><li>222 residential homes</li><li>Approximately 2,220 residents</li><li>Complete security and gate-controlled access</li><li>Modern amenities (clubhouse, multi-purpose building, sports courts)</li><li>Professional community management</li></ul>Our community emphasizes safety, convenience, and a strong sense of belonging.'
    ],
    // FAQ Category
    [
        'keywords' => ['faq', 'frequently asked', 'common questions', 'help'],
        'response' => '<strong>Popular questions about VictorianPass:</strong><br><ol><li><strong>How do I register?</strong> → Click Register and fill in your information</li><li><strong>How do I request a visitor pass?</strong> → Go to Reservations and submit visitor details</li><li><strong>What amenities are available?</strong> → Clubhouse, Multi-Purpose Building, Basketball Court, Tennis Court</li><li><strong>How does the QR system work?</strong> → Scan your QR code at entry points for secure access</li><li><strong>How do I contact administration?</strong> → Email: admin@victorianpass.com or call during office hours</li></ol>Need more help? Our support team is ready to assist.'
    ],
    // Smart Waste Segregation - How to Use Station
    [
        'keywords' => ['ecopoint', 'waste', 'segregation', 'smart waste station', 'segregation station', 'how to use', 'use station', 'recycle', 'recycling'],
        'response' => $ecoPointProgramSummary . "<br><br><strong>How to use the VHEcoPoint station:</strong><br><ol><li>Log in to VictorianPass and prepare your resident QR code.</li><li>Go to the VHEcoPoint Smart Waste Segregation Station in Victorian Heights Subdivision.</li><li>Scan your VictorianPass QR code to authenticate your session.</li><li>Deposit accepted recyclables: Plastic (PET Bottles <=1000ml), Aluminum Cans, and Paper &amp; Cardboard.</li><li>The station records the material weight and credits points automatically to your VictorianPass account.</li><li>Check your balance and redeem rewards inside VictorianPass once you have enough points for a full reward.</li></ol><strong>Recycling reminder:</strong> Deposit clean, dry, properly sorted recyclables only."
    ],
    // Smart Waste - Points Rates & Limits
    [
        'keywords' => ['points', 'how many points', 'points per kg', 'earn points', 'waste points', 'rates', 'ecopoints', 'earn ecopoints', 'weekly cap', 'daily limit', 'maximum balance', 'point expiry', 'expire'],
        'response' => '<strong>VHEcoPoint Rates and Limits:</strong><br><ul><li><strong>Plastic (PET Bottles <=1000ml):</strong> 55 pts/kg</li><li><strong>Aluminum Cans:</strong> 140 pts/kg</li><li><strong>Paper &amp; Cardboard:</strong> 30 pts/kg</li><li><strong>Point value:</strong> 1 point = Php 0.30 in amenity value</li><li><strong>Daily limit:</strong> 3 sessions and 100 points per day</li><li><strong>Weekly cap:</strong> 250 points, resetting every Monday at 12:00 AM</li><li><strong>Maximum balance:</strong> 3,000 points</li><li><strong>Expiry:</strong> Points expire after 24 months</li></ul>Points are credited automatically after a successful VHEcoPoint deposit in VictorianPass.'
    ],
    // Amenity Points - Free Hour Requirements
    [
        'keywords' => ['free hour', 'points for amenity', 'free amenity', 'how many points', 'basketball', 'tennis', 'clubhouse', 'multi-purpose', 'one hour free', 'redeem points', 'redeem my points', 'redemption', 'partial discounts'],
        'response' => '<strong>VHEcoPoint Redemption Rates:</strong><br><ul><li><strong>300 points</strong> = 1 free hour at the Basketball Court or Tennis Court</li><li><strong>600 points</strong> = 1 free hour at the Clubhouse</li><li><strong>750 points</strong> = 1 free hour at the Multi-Purpose Building</li></ul><strong>Important:</strong> Partial discounts are not available. VHEcoPoint rewards use full point redemption only.<br><br><strong>How to redeem:</strong><br><ol><li>Log in to VictorianPass.</li><li>Check your EcoPoint balance.</li><li>Go to amenity reservation.</li><li>Select an eligible amenity and time slot.</li><li>Redeem the full required points for the free hour.</li></ol>'
    ],
    // Check Current Points Balance
    [
        'keywords' => ['my points', 'total points', 'current points', 'how many points do i have', 'points balance', 'check points'],
        'response' => "<strong>Check Your Points Balance:</strong><br><br>To view your total VHEcoPoint points right now:<br><br><ol><li>Log in to your VictorianPass account</li><li>Go to your Dashboard</li><li>Look for the 'Smart Waste' or 'My Points' section</li><li>Your current points balance will be displayed prominently</li><li>You can also see:<br><ul><li>Points earned this week</li><li>Points earned this month</li><li>Your deposit history with dates and amounts</li><li>Available amenity bookings with your points</li><li>Remaining daily/weekly point limits</li></ul></li></ol>Your points are updated instantly after each deposit at the VHEcoPoint Smart Waste Segregation Station. The LCD screen at the station also shows your current balance when you scan your QR code.<br><br><strong>Need help?</strong> Contact administration at admin@victorianpass.com or call +63 (2) 1234-5678."
    ],
    // Smart Waste - How to Get Started
    [
        'keywords' => ['get started', 'how to start', 'begin', 'join program', 'register qr', 'first time', 'how do i earn ecopoints'],
        'response' => '<strong>How to start earning EcoPoints:</strong><br><ol><li>Log in to your resident VictorianPass account.</li><li>Open your QR code and bring it to the VHEcoPoint station.</li><li>Prepare accepted recyclables: Plastic (PET Bottles <=1000ml), Aluminum Cans, and Paper &amp; Cardboard.</li><li>Scan your QR code to authenticate your session.</li><li>Deposit your recyclables and let the station record their weight.</li><li>Points are credited automatically to your VictorianPass account.</li></ol><strong>Program rules:</strong><br><ul><li>100 points maximum per day</li><li>3 sessions maximum per day</li><li>250 points maximum per week, reset every Monday at 12:00 AM</li><li>3,000 points maximum balance</li><li>Points expire after 24 months</li></ul>'
    ],
    // Smart Waste - Environmental Impact
    [
        'keywords' => ['environmental impact', 'help environment', 'sustainability', 'eco-friendly', 'save planet', 'green', 'environmental benefit'],
        'response' => "<strong>Your Waste Segregation Impact on Victorian Heights!</strong><br><br>Each contribution makes a real difference:<br><br><strong>Community Impact:</strong><br><ul><li>Reduce landfill waste significantly</li><li>Lower greenhouse gas emissions</li><li>Conserve natural resources</li><li>Support sustainable local initiatives</li><li>Build community environmental awareness</li></ul><strong>Your Reward:</strong><br><ul><li>Earn valuable points for every deposit</li><li>Get free amenity hours</li><li>Be recognized as an eco-conscious resident</li><li>Help achieve our community sustainability goals</li></ul>By participating in Smart Waste Segregation, you're not just earning points for free amenity time — you're actively contributing to a greener Victorian Heights Subdivision!<br><br>Every kg of plastic, aluminum, and paper you deposit helps us move toward zero waste goals."
    ],
];

function isResidentOnlyAIQuery($query) {
    $residentOnlyKeywords = [
        'ecopoint',
        'smart waste',
        'waste segregation',
        'segregation station',
        'recycle',
        'recycling',
        'my points',
        'points balance',
        'current points',
        'total points',
        'earn points',
        'earn ecopoints',
        'redeem points',
        'redeem my points',
        'reward',
        'rewards',
        'free hour',
        'amenity points',
        'recycling activities'
    ];

    $queryLower = strtolower($query);
    foreach ($residentOnlyKeywords as $keyword) {
        if (strpos($queryLower, $keyword) !== false) {
            return true;
        }
    }

    return false;
}

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
    return 'Thank you for your question! VictorianPass can help with amenity reservations, visitor access, incident reporting, and EcoPoint recycling rewards for residents. For specific concerns, please contact our administration team at admin@victorianpass.com or call +63 (2) 1234-5678.';
}

// Restrict resident-only perks from visitor and public sessions
if (!$isResident && isResidentOnlyAIQuery($query)) {
    $aiResponse = 'EcoPoint recycling points, rewards, and redemption perks are available only to resident users. Please log in with a resident account to view point balances, recycling history, and redemption options.';
} else {
    $aiResponse = findResponse($query, $knowledgeBase);
}

$response['success'] = true;
$response['response'] = $aiResponse;

echo json_encode($response);
?>
