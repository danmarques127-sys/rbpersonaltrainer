# RB Personal Trainer — SaaS Platform

RB Personal Trainer is a **production-ready SaaS platform** built with **PHP and MySQL**, designed to manage **clients, personal trainers, goals, workouts, and communication** within a single, secure system.

This project was developed as a **real-world SaaS product**, with focus on **security, scalability, and clean separation of concerns**, covering the full lifecycle from authentication to role-based dashboards and email workflows.

---

## 🌐 Platform Access

🔗 **Production Website**  
https://www.rbpersonaltrainer.com

🔗 **Static Preview (UI demo)**  
https://danmarques127-sys.github.io/rbpersonaltrainer/

---

## 🎯 Product Vision

The goal of RB Personal Trainer is to provide a **centralized platform for fitness professionals** to manage clients efficiently, while giving clients a clear view of their **goals, progress, and communication** with trainers.

The platform was designed to support:
- Real client–trainer workflows
- Secure multi-role access
- Scalable SaaS-style architecture
- Long-term feature expansion

---

## 🚀 Features

### Public Website
- Responsive marketing pages
- Services, testimonials, and contact sections
- SEO-ready structure
- Optimized assets and icons

---

### Authentication & Roles
- Secure login system
- Role-based access control:
  - Client
  - Personal Trainer
  - Admin
- Password reset via email
- Session protection and access guards

---

### Client Dashboard
- Profile management
- Goal tracking and progress history
- Workout plans
- Progress photo gallery
- Messaging with assigned trainer

---

### Trainer Dashboard
- Client management
- Workout plan creation
- Goal assignment and updates
- Client progress monitoring
- Internal messaging system

---

### Admin Dashboard
- User management
- Invitation-based onboarding
- Platform-level control features

---

## 🛠️ Tech Stack

- **Backend:** PHP (custom architecture, no framework)
- **Database:** MySQL (PDO, prepared statements)
- **Frontend:** HTML5, CSS3, JavaScript
- **Authentication:** Sessions with role-based guards
- **Email:** SMTP (Mailtrap / Brevo supported)
- **Security:** Environment variables, access control, input validation
- **Version Control:** Git & GitHub

---

## 🧱 Architecture Overview

- Clean separation between core logic and presentation
- Centralized authentication and role guards
- Modular dashboard structure per role
- Environment-based configuration (`.env`)
- Prepared for future API and mobile integration

---

## 📂 Project Structure

/
├── core/ # Core system (auth, config, bootstrap)
├── dashboards/ # Role-based dashboards
├── assets/ # CSS, JS, images
├── images/ # Static images and media
├── cron/ # Scheduled tasks
├── phpmailer/ # Email handling
├── index.php # Public entry point
├── login.php
├── .env.example # Environment template


---

## 🧪 Demo & Preview

- **Static UI Preview:**  
  https://danmarques127-sys.github.io/rbpersonaltrainer/

(The static demo showcases layout and structure. Core SaaS functionality runs on the production environment.)

---

## 👤 Author

**Dangelo Marques**  
Full-Stack Developer — SaaS platforms, dashboards, and PHP systems

Responsible for **architecture, backend, frontend, authentication, role management, security, and deployment**.

---

## 📄 License

This is a **real commercial SaaS project**.  
Source code is published for **demonstration and evaluation purposes only**.
