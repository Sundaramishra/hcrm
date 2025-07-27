<?php
require_once 'config/database.php';

try {
    $db = Database::getInstance();
    
    // Create database if not exists
    $db->query("CREATE DATABASE IF NOT EXISTS hospital_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("USE hospital_management");
    
    // Users and Roles Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS roles (
            id INT PRIMARY KEY AUTO_INCREMENT,
            role_name VARCHAR(50) UNIQUE NOT NULL,
            role_display_name VARCHAR(100) NOT NULL,
            permissions JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role_id INT NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            phone VARCHAR(15),
            address TEXT,
            date_of_birth DATE,
            gender ENUM('Male', 'Female', 'Other'),
            employee_id VARCHAR(20) UNIQUE,
            is_active BOOLEAN DEFAULT TRUE,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        )
    ");
    
    // Patients Table
    $db->query("
        CREATE TABLE IF NOT EXISTS patients (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id VARCHAR(20) UNIQUE NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            date_of_birth DATE NOT NULL,
            gender ENUM('Male', 'Female', 'Other') NOT NULL,
            blood_group VARCHAR(5),
            phone VARCHAR(15) NOT NULL,
            email VARCHAR(100),
            address TEXT NOT NULL,
            emergency_contact_name VARCHAR(100),
            emergency_contact_phone VARCHAR(15),
            marital_status ENUM('Single', 'Married', 'Divorced', 'Widowed'),
            occupation VARCHAR(100),
            medical_history TEXT,
            allergies TEXT,
            current_medications TEXT,
            insurance_provider VARCHAR(100),
            insurance_policy_number VARCHAR(50),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Doctors Table
    $db->query("
        CREATE TABLE IF NOT EXISTS doctors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            license_number VARCHAR(50) UNIQUE NOT NULL,
            specialization VARCHAR(100) NOT NULL,
            qualification VARCHAR(200),
            experience_years INT DEFAULT 0,
            consultation_fee DECIMAL(10,2) DEFAULT 0,
            is_available BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    
    // Departments Table
    $db->query("
        CREATE TABLE IF NOT EXISTS departments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            head_doctor_id INT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (head_doctor_id) REFERENCES doctors(id)
        )
    ");
    
    // Rooms and Beds Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS rooms (
            id INT PRIMARY KEY AUTO_INCREMENT,
            room_number VARCHAR(10) UNIQUE NOT NULL,
            room_type ENUM('General Ward', 'Private Room', 'ICU', 'Emergency Room', 'Operation Theater', 'Delivery Room', 'Pediatric Ward') NOT NULL,
            department_id INT,
            floor_number INT NOT NULL,
            capacity INT DEFAULT 1,
            daily_rate DECIMAL(10,2) DEFAULT 0,
            amenities TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES departments(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS beds (
            id INT PRIMARY KEY AUTO_INCREMENT,
            bed_number VARCHAR(10) NOT NULL,
            room_id INT NOT NULL,
            bed_type ENUM('General', 'Private', 'ICU', 'Emergency', 'Pediatric', 'Maternity') NOT NULL,
            status ENUM('Available', 'Occupied', 'Maintenance', 'Reserved') DEFAULT 'Available',
            daily_rate DECIMAL(10,2) DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            UNIQUE KEY unique_bed_room (bed_number, room_id)
        )
    ");
    
    // Bed Assignments Table
    $db->query("
        CREATE TABLE IF NOT EXISTS bed_assignments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            bed_id INT NOT NULL,
            admission_date DATETIME NOT NULL,
            discharge_date DATETIME NULL,
            assigned_by INT NOT NULL,
            notes TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (bed_id) REFERENCES beds(id),
            FOREIGN KEY (assigned_by) REFERENCES users(id)
        )
    ");
    
    // Appointments Table
    $db->query("
        CREATE TABLE IF NOT EXISTS appointments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            appointment_id VARCHAR(20) UNIQUE NOT NULL,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            duration_minutes INT DEFAULT 30,
            reason TEXT,
            status ENUM('Scheduled', 'Confirmed', 'In Progress', 'Completed', 'Cancelled', 'No Show') DEFAULT 'Scheduled',
            notes TEXT,
            consultation_fee DECIMAL(10,2) DEFAULT 0,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
    
    // Medical Records Table
    $db->query("
        CREATE TABLE IF NOT EXISTS medical_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            appointment_id INT,
            visit_date DATE NOT NULL,
            symptoms TEXT,
            diagnosis TEXT,
            treatment TEXT,
            prescription TEXT,
            follow_up_date DATE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id),
            FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        )
    ");
    
    // Pharmacy Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS medicines (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            generic_name VARCHAR(200),
            manufacturer VARCHAR(100),
            category VARCHAR(100),
            strength VARCHAR(50),
            unit VARCHAR(20),
            purchase_price DECIMAL(10,2) DEFAULT 0,
            selling_price DECIMAL(10,2) DEFAULT 0,
            stock_quantity INT DEFAULT 0,
            minimum_stock INT DEFAULT 10,
            expiry_date DATE,
            batch_number VARCHAR(50),
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS prescriptions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            prescription_date DATE NOT NULL,
            status ENUM('Pending', 'Dispensed', 'Partially Dispensed', 'Cancelled') DEFAULT 'Pending',
            total_amount DECIMAL(10,2) DEFAULT 0,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS prescription_medicines (
            id INT PRIMARY KEY AUTO_INCREMENT,
            prescription_id INT NOT NULL,
            medicine_id INT NOT NULL,
            quantity INT NOT NULL,
            dosage VARCHAR(100),
            frequency VARCHAR(100),
            duration VARCHAR(100),
            instructions TEXT,
            unit_price DECIMAL(10,2) DEFAULT 0,
            total_price DECIMAL(10,2) DEFAULT 0,
            dispensed_quantity INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        )
    ");
    
    // Laboratory Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS lab_tests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            test_name VARCHAR(200) NOT NULL,
            test_code VARCHAR(20) UNIQUE NOT NULL,
            category VARCHAR(100),
            price DECIMAL(10,2) DEFAULT 0,
            normal_range VARCHAR(200),
            unit VARCHAR(50),
            description TEXT,
            preparation_instructions TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS lab_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            request_date DATE NOT NULL,
            status ENUM('Pending', 'Sample Collected', 'In Progress', 'Completed', 'Cancelled') DEFAULT 'Pending',
            total_amount DECIMAL(10,2) DEFAULT 0,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS lab_request_tests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            lab_request_id INT NOT NULL,
            lab_test_id INT NOT NULL,
            result_value VARCHAR(500),
            result_status ENUM('Pending', 'Normal', 'Abnormal', 'Critical') DEFAULT 'Pending',
            technician_id INT,
            completed_at TIMESTAMP NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id),
            FOREIGN KEY (technician_id) REFERENCES users(id)
        )
    ");
    
    // Blood Bank Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS blood_donors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            donor_id VARCHAR(20) UNIQUE NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            date_of_birth DATE NOT NULL,
            gender ENUM('Male', 'Female', 'Other') NOT NULL,
            blood_group VARCHAR(5) NOT NULL,
            phone VARCHAR(15) NOT NULL,
            email VARCHAR(100),
            address TEXT NOT NULL,
            weight DECIMAL(5,2),
            last_donation_date DATE,
            medical_history TEXT,
            is_eligible BOOLEAN DEFAULT TRUE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS blood_inventory (
            id INT PRIMARY KEY AUTO_INCREMENT,
            blood_group VARCHAR(5) NOT NULL,
            component_type ENUM('Whole Blood', 'Red Blood Cells', 'Plasma', 'Platelets', 'White Blood Cells') NOT NULL,
            units_available INT DEFAULT 0,
            units_reserved INT DEFAULT 0,
            expiry_date DATE NOT NULL,
            donor_id INT,
            collection_date DATE NOT NULL,
            status ENUM('Available', 'Reserved', 'Used', 'Expired') DEFAULT 'Available',
            storage_location VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (donor_id) REFERENCES blood_donors(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS blood_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            doctor_id INT NOT NULL,
            blood_group VARCHAR(5) NOT NULL,
            component_type ENUM('Whole Blood', 'Red Blood Cells', 'Plasma', 'Platelets', 'White Blood Cells') NOT NULL,
            units_requested INT NOT NULL,
            priority ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            status ENUM('Pending', 'Approved', 'Fulfilled', 'Cancelled') DEFAULT 'Pending',
            request_date DATE NOT NULL,
            required_date DATE NOT NULL,
            reason TEXT,
            approved_by INT,
            fulfilled_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            FOREIGN KEY (fulfilled_by) REFERENCES users(id)
        )
    ");
    
    // Organ Donation Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS organ_donors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            donor_id VARCHAR(20) UNIQUE NOT NULL,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            date_of_birth DATE NOT NULL,
            gender ENUM('Male', 'Female', 'Other') NOT NULL,
            blood_group VARCHAR(5) NOT NULL,
            phone VARCHAR(15) NOT NULL,
            email VARCHAR(100),
            address TEXT NOT NULL,
            emergency_contact_name VARCHAR(100),
            emergency_contact_phone VARCHAR(15),
            medical_history TEXT,
            organs_to_donate JSON,
            consent_date DATE NOT NULL,
            consent_document VARCHAR(255),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS organ_recipients (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            organ_needed VARCHAR(50) NOT NULL,
            blood_group VARCHAR(5) NOT NULL,
            priority ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            status ENUM('Waiting', 'Matched', 'Transplanted', 'Cancelled') DEFAULT 'Waiting',
            registration_date DATE NOT NULL,
            medical_urgency TEXT,
            compatibility_notes TEXT,
            doctor_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (doctor_id) REFERENCES doctors(id)
        )
    ");
    
    // Insurance Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS insurance_companies (
            id INT PRIMARY KEY AUTO_INCREMENT,
            company_name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(100),
            phone VARCHAR(15),
            email VARCHAR(100),
            address TEXT,
            website VARCHAR(200),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS insurance_policies (
            id INT PRIMARY KEY AUTO_INCREMENT,
            patient_id INT NOT NULL,
            insurance_company_id INT NOT NULL,
            policy_number VARCHAR(100) NOT NULL,
            policy_holder_name VARCHAR(100) NOT NULL,
            coverage_amount DECIMAL(12,2) NOT NULL,
            deductible_amount DECIMAL(10,2) DEFAULT 0,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('Active', 'Expired', 'Cancelled', 'Suspended') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (insurance_company_id) REFERENCES insurance_companies(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS insurance_claims (
            id INT PRIMARY KEY AUTO_INCREMENT,
            claim_number VARCHAR(50) UNIQUE NOT NULL,
            patient_id INT NOT NULL,
            insurance_policy_id INT NOT NULL,
            bill_id INT,
            claim_amount DECIMAL(12,2) NOT NULL,
            approved_amount DECIMAL(12,2) DEFAULT 0,
            status ENUM('Submitted', 'Under Review', 'Approved', 'Rejected', 'Paid') DEFAULT 'Submitted',
            submission_date DATE NOT NULL,
            approval_date DATE NULL,
            rejection_reason TEXT,
            documents JSON,
            processed_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (insurance_policy_id) REFERENCES insurance_policies(id),
            FOREIGN KEY (processed_by) REFERENCES users(id)
        )
    ");
    
    // Billing Tables
    $db->query("
        CREATE TABLE IF NOT EXISTS service_categories (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS services (
            id INT PRIMARY KEY AUTO_INCREMENT,
            service_name VARCHAR(200) NOT NULL,
            service_code VARCHAR(20) UNIQUE NOT NULL,
            category_id INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES service_categories(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS bills (
            id INT PRIMARY KEY AUTO_INCREMENT,
            bill_number VARCHAR(20) UNIQUE NOT NULL,
            patient_id INT NOT NULL,
            appointment_id INT NULL,
            bill_date DATE NOT NULL,
            due_date DATE NOT NULL,
            subtotal DECIMAL(12,2) DEFAULT 0,
            tax_amount DECIMAL(10,2) DEFAULT 0,
            discount_amount DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(12,2) NOT NULL,
            paid_amount DECIMAL(12,2) DEFAULT 0,
            balance_amount DECIMAL(12,2) DEFAULT 0,
            status ENUM('Pending', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled') DEFAULT 'Pending',
            payment_terms VARCHAR(100),
            notes TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (appointment_id) REFERENCES appointments(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS bill_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            bill_id INT NOT NULL,
            service_id INT,
            medicine_id INT,
            item_name VARCHAR(200) NOT NULL,
            quantity INT DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
            FOREIGN KEY (service_id) REFERENCES services(id),
            FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        )
    ");
    
    $db->query("
        CREATE TABLE IF NOT EXISTS payments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            payment_id VARCHAR(20) UNIQUE NOT NULL,
            bill_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_method ENUM('Cash', 'Card', 'UPI', 'Net Banking', 'Cheque', 'Insurance') NOT NULL,
            payment_date DATE NOT NULL,
            transaction_id VARCHAR(100),
            reference_number VARCHAR(100),
            notes TEXT,
            received_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bill_id) REFERENCES bills(id),
            FOREIGN KEY (received_by) REFERENCES users(id)
        )
    ");
    
    // Activity Logs Table
    $db->query("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    // Insert default roles
    $roles = [
        ['admin', 'Administrator', '["all"]'],
        ['doctor', 'Doctor', '["patients.view", "patients.create", "patients.edit", "appointments.view", "appointments.create", "appointments.edit", "medical_records.view", "medical_records.create", "medical_records.edit", "prescriptions.view", "prescriptions.create", "lab_requests.view", "lab_requests.create", "blood_requests.view", "blood_requests.create", "organ_recipients.view"]'],
        ['nurse', 'Nurse', '["patients.view", "patients.edit", "appointments.view", "medical_records.view", "beds.view", "beds.edit", "blood_inventory.view"]'],
        ['receptionist', 'Receptionist', '["patients.view", "patients.create", "patients.edit", "appointments.view", "appointments.create", "appointments.edit", "bills.view", "payments.view"]'],
        ['accountant', 'Accountant', '["bills.view", "bills.create", "bills.edit", "payments.view", "payments.create", "insurance_claims.view", "reports.view"]'],
        ['lab_technician', 'Lab Technician', '["lab_requests.view", "lab_requests.edit", "lab_tests.view", "patients.view"]'],
        ['pharmacist', 'Pharmacist', '["medicines.view", "medicines.create", "medicines.edit", "prescriptions.view", "prescriptions.edit", "patients.view"]']
    ];
    
    foreach ($roles as $role) {
        $db->query(
            "INSERT IGNORE INTO roles (role_name, role_display_name, permissions) VALUES (?, ?, ?)",
            $role
        );
    }
    
    // Insert default admin user
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $db->query(
        "INSERT IGNORE INTO users (username, email, password_hash, role_id, first_name, last_name, employee_id) 
         VALUES ('admin', 'admin@hospital.com', ?, 1, 'Hospital', 'Administrator', 'EMP001')",
        [$adminPassword]
    );
    
    // Insert default service categories
    $serviceCategories = [
        ['Consultation', 'Doctor consultation fees'],
        ['Laboratory', 'Lab tests and investigations'],
        ['Pharmacy', 'Medicine and pharmacy services'],
        ['Room Charges', 'Room and bed charges'],
        ['Surgery', 'Surgical procedures'],
        ['Emergency', 'Emergency services'],
        ['Radiology', 'X-ray, CT, MRI services']
    ];
    
    foreach ($serviceCategories as $category) {
        $db->query(
            "INSERT IGNORE INTO service_categories (name, description) VALUES (?, ?)",
            $category
        );
    }
    
    // Insert some default services
    $services = [
        ['General Consultation', 'CONS001', 1, 500.00],
        ['Specialist Consultation', 'CONS002', 1, 1000.00],
        ['Emergency Consultation', 'CONS003', 6, 1500.00],
        ['Complete Blood Count', 'LAB001', 2, 300.00],
        ['Blood Sugar Test', 'LAB002', 2, 150.00],
        ['X-Ray Chest', 'RAD001', 7, 400.00],
        ['ECG', 'RAD002', 7, 200.00],
        ['General Ward Bed', 'ROOM001', 4, 800.00],
        ['Private Room', 'ROOM002', 4, 2000.00],
        ['ICU Bed', 'ROOM003', 4, 5000.00]
    ];
    
    foreach ($services as $service) {
        $db->query(
            "INSERT IGNORE INTO services (service_name, service_code, category_id, price) VALUES (?, ?, ?, ?)",
            $service
        );
    }
    
    // Insert default insurance companies
    $insuranceCompanies = [
        ['HDFC ERGO Health Insurance', 'Rahul Kumar', '1800-266-5700', 'care@hdfcergo.com'],
        ['ICICI Lombard Health Insurance', 'Priya Singh', '1800-266-7766', 'care@icicilombard.com'],
        ['Star Health Insurance', 'Amit Sharma', '1800-425-2255', 'care@starhealth.in'],
        ['Max Bupa Health Insurance', 'Neha Gupta', '1800-123-4004', 'care@maxbupa.com']
    ];
    
    foreach ($insuranceCompanies as $company) {
        $db->query(
            "INSERT IGNORE INTO insurance_companies (company_name, contact_person, phone, email) VALUES (?, ?, ?, ?)",
            $company
        );
    }
    
    echo "✅ Database setup completed successfully!\n";
    echo "📊 All tables created with sample data\n";
    echo "🔐 Default admin user created: admin@hospital.com / admin123\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up database: " . $e->getMessage() . "\n";
}
?>