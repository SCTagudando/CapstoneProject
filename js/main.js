// js/main.js — S-Five Inland Resort

document.addEventListener('DOMContentLoaded', function () {

    // ===== NAVBAR SCROLL =====
    const navbar = document.getElementById('navbar');
    if (navbar) {
        window.addEventListener('scroll', function () {
            if (window.scrollY > 60) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    }

    // ===== MOBILE NAV TOGGLE =====
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.querySelector('.nav-links');
    if (navToggle && navLinks) {
        navToggle.addEventListener('click', function () {
            navLinks.style.display = navLinks.style.display === 'flex' ? 'none' : 'flex';
            navLinks.style.flexDirection = 'column';
            navLinks.style.position = 'absolute';
            navLinks.style.top = '100%';
            navLinks.style.left = '0';
            navLinks.style.right = '0';
            navLinks.style.background = 'rgba(26,58,46,0.97)';
            navLinks.style.padding = '1rem 2rem 1.5rem';
            navLinks.style.gap = '1rem';
            navLinks.style.zIndex = '999';
        });
    }

    // ===== SCROLL REVEAL ANIMATION =====
    const revealEls = document.querySelectorAll('.cottage-card, .amenity-item, .stat-card');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    setTimeout(() => {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }, i * 80);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        revealEls.forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(el);
        });
    }

    // ===== MIN DATE ENFORCEMENT ON CHECK_OUT =====
    const checkIn = document.getElementById('check_in');
    const checkOut = document.getElementById('check_out');
    if (checkIn && checkOut) {
        checkIn.addEventListener('change', function () {
            const d = new Date(this.value);
            d.setDate(d.getDate() + 1);
            checkOut.min = d.toISOString().split('T')[0];
            if (checkOut.value && checkOut.value <= this.value) {
                checkOut.value = '';
            }
        });
    }
});
