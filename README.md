# RB Personal Trainer — SaaS Platform

RB Personal Trainer is a full-featured **Personal Trainer SaaS platform** built with **PHP and MySQL**, designed to manage clients, trainers, goals, workouts and communication in a single system.

This project was developed as a **real-world SaaS architecture**, focusing on security, scalability and clean separation of concerns.

---
## 🌐 Website

🔗 https://www.rbpersonaltrainer.com

---
## 🚀 Features

### Public Website
- Responsive marketing pages
- Services, testimonials and contact pages
- SEO-ready structure
- Optimized assets and icons

### Authentication & Roles
- Secure login system
- Role-based access control:
  - Client
  - Personal Trainer
  - Admin
- Password reset via email
- Session protection and access guards

### Client Dashboard
- Profile management
- Goal tracking & progress history
- Workout plans
- Progress photo gallery
- Messaging with trainer

### Trainer Dashboard
- Client management
- Workout plan creation
- Goal assignment and updates
- Client progress monitoring
- Internal messaging system

### Admin Dashboard
- User management
- Invitations system
- Platform control features

---

## 🛠️ Tech Stack

- **Backend:** PHP (custom architecture, no framework)
- **Database:** MySQL (PDO)
- **Frontend:** HTML5, CSS3, JavaScript
- **Authentication:** Sessions + role-based guards
- **Email:** SMTP (Mailtrap / Brevo supported)
- **Security:** Environment variables, prepared statements, access control
- **Version Control:** Git & GitHub

---

## 📂 Project Structure

/
├── core/ # Core system (auth, config, bootstrap)
├── dashboards/ # Role-based dashboards
├── assets/ # CSS, JS, images
├── images/ # Static images and media
├── cron/ # Scheduled tasks
├── phpmailer/ # Email handling
├── index.php # Entry point
├── login.php
├── .env.example # Environment template

## 🌐 Live Demo

**GitHub Pages (static demo):**  
👉 https://danmarques127-sys.github.io/rbpersonaltrainer/

## 🎯 Purpose

This project was built to demonstrate:
- SaaS architecture in pure PHP
- Secure authentication and role handling
- Real client/trainer workflows
- Production-ready structure

It is suitable for:
- SaaS MVPs
- Fitness platforms
- Client-management systems
- Portfolio and commercial projects
- 
## 👤 Author

**Dangelo Marques**  
Full Stack Developer  
Specialized in PHP SaaS platforms, dashboards and web systems.

## 📄 License

This project is for portfolio and demonstration purposes.

