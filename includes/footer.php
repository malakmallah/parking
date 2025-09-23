</main>

  <footer id="footer" class="footer">
    <div class="container">
      <h3 class="sitename">LIU Parking System</h3>
      <p>Smart parking management across all Lebanese International University campuses.<br>Secure, efficient, and user-friendly solutions for the entire LIU community.</p>
      <div class="social-links">
        <a href="#"><i class="bi bi-twitter-x"></i></a>
        <a href="#"><i class="bi bi-facebook"></i></a>
        <a href="#"><i class="bi bi-instagram"></i></a>
        <a href="#"><i class="bi bi-linkedin"></i></a>
      </div>
      
      <div class="container mt-4">
        <div class="row">
          <div class="col-md-4">
            <h5>Contact Support</h5>
            <p><i class="fas fa-phone"></i> +961 1 200 800<br>
            <i class="fas fa-envelope"></i> parking-support@liu.edu.lb</p>
          </div>
          <div class="col-md-4">
            <h5>Emergency Contact</h5>
            <div style="background: #dc3545; padding: 10px; border-radius: 5px;">
              <p style="color: white; margin: 0;"><i class="fas fa-exclamation-triangle"></i> <strong>+961 1 200 911</strong></p>
            </div>
          </div>
          <div class="col-md-4">
            <h5>System Status</h5>
            <p style="color: #28a745;"><i class="fas fa-circle" style="font-size: 8px;"></i> All Systems Operational</p>
          </div>
        </div>
      </div>
      
      <div class="copyright text-center">
        <p>&copy; <span>Copyright</span> <strong class="px-1 sitename"><?php echo date('Y'); ?> Lebanese International University</strong> <span>All Rights Reserved</span></p>
      </div>
      <div class="credits">
        Parking Management System | Developed by <a href="#">LIU IT Department</a>
      </div>
    </div>
  </footer>

  <style>
    /* Footer Styles */
    .footer {
      color: var(--contrast-color);
      background-color: var(--accent-color);
      font-size: 14px;
      text-align: center;
      padding: 30px 0;
      position: relative;
    }

    .footer h3 {
      font-size: 36px;
      font-weight: 700;
      position: relative;
      color: var(--contrast-color);
      padding: 0;
      margin: 0 0 15px 0;
    }

    .footer p {
      font-size: 15px;
      font-style: italic;
      padding: 0;
      margin: 0 0 30px 0;
    }

    .footer .social-links {
      margin: 0 0 30px 0;
    }

    .footer .social-links a {
      font-size: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--liu-gold);
      color: var(--accent-color);
      line-height: 1;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      text-align: center;
      margin: 0 4px;
      transition: 0.3s;
    }

    .footer .social-links a:hover {
      background: var(--contrast-color);
    }

    .footer .copyright {
      padding-top: 25px;
      border-top: 1px solid color-mix(in srgb, var(--contrast-color), transparent 90%);
    }

    .footer .credits {
      margin-top: 5px;
      font-size: 13px;
      color: color-mix(in srgb, var(--contrast-color), transparent 30%);
    }

    .footer h5 {
      color: var(--liu-gold);
      margin-bottom: 15px;
    }

    .footer .row p {
      color: rgba(255, 255, 255, 0.8);
      font-style: normal;
    }

    /* Scroll Top Button */
    .scroll-top {
      position: fixed;
      visibility: hidden;
      opacity: 0;
      right: 15px;
      bottom: 15px;
      z-index: 99999;
      background-color: var(--accent-color);
      width: 44px;
      height: 44px;
      border-radius: 50px;
      transition: all 0.4s;
    }

    .scroll-top i {
      font-size: 24px;
      color: var(--contrast-color);
      line-height: 0;
    }

    .scroll-top:hover {
      background-color: var(--liu-gold);
    }

    .scroll-top.active {
      visibility: visible;
      opacity: 1;
    }
  </style>

  <!-- Scroll Top -->
  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Vendor JS Files -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/purecounter/1.1.0/purecounter_vanilla.js"></script>

  <!-- Additional JS Files -->
  <?php if (isset($additionalJS) && !empty($additionalJS)): ?>
    <?php foreach ($additionalJS as $jsFile): ?>
      <script src="<?php echo htmlspecialchars($jsFile); ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>

  <script>
    /**
     * Preloader
     */
    const preloader = document.querySelector('#preloader');
    if (preloader) {
      window.addEventListener('load', () => {
        preloader.remove();
      });
    }

    /**
     * Scroll top button
     */
    let scrollTop = document.querySelector('.scroll-top');
    
    function toggleScrollTop() {
      if (scrollTop) {
        window.scrollY > 100 ? scrollTop.classList.add('active') : scrollTop.classList.remove('active');
      }
    }
    
    if (scrollTop) {
      scrollTop.addEventListener('click', (e) => {
        e.preventDefault();
        window.scrollTo({
          top: 0,
          behavior: 'smooth'
        });
      });
    }

    window.addEventListener('load', toggleScrollTop);
    document.addEventListener('scroll', toggleScrollTop);

    /**
     * Animation on scroll function and init
     */
    function aosInit() {
      AOS.init({
        duration: 600,
        easing: 'ease-in-out',
        once: true,
        mirror: false
      });
    }
    window.addEventListener('load', aosInit);

    /**
     * Init PureCounter
     */
    new PureCounter();

    /**
     * Mobile nav toggle
     */
    const mobileNavToggle = document.querySelector('.mobile-nav-toggle');
    
    function mobileNavToogle() {
      document.querySelector('body').classList.toggle('mobile-nav-active');
      mobileNavToggle.classList.toggle('bi-list');
      mobileNavToggle.classList.toggle('bi-x');
    }
    
    if (mobileNavToggle) {
      mobileNavToggle.addEventListener('click', mobileNavToogle);
    }

    /**
     * Hide mobile nav on same-page/hash links
     */
    document.querySelectorAll('#navmenu a').forEach(navmenu => {
      navmenu.addEventListener('click', () => {
        if (document.querySelector('.mobile-nav-active')) {
          mobileNavToogle();
        }
      });
    });

    /**
     * Toggle mobile nav dropdowns
     */
    document.querySelectorAll('.navmenu .toggle-dropdown').forEach(navmenu => {
      navmenu.addEventListener('click', function(e) {
        e.preventDefault();
        this.parentNode.classList.toggle('active');
        this.parentNode.nextElementSibling.classList.toggle('dropdown-active');
        e.stopImmediatePropagation();
      });
    });

    /**
     * Smooth scrolling for navigation links
     */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        }
      });
    });

    /**
     * Auto-hide connection status after 5 seconds
     */
    setTimeout(function() {
      const status = document.querySelector('.connection-status');
      if (status) {
        status.style.transition = 'opacity 0.5s ease';
        status.style.opacity = '0.7';
        setTimeout(() => {
          status.style.display = 'none';
        }, 3000);
      }
    }, 5000);

    /**
     * Navbar scroll effect
     */
    window.addEventListener('scroll', function() {
      const header = document.querySelector('.header');
      if (window.scrollY > 50) {
        header.style.boxShadow = '0 0 25px rgba(0, 0, 0, 0.08)';
      } else {
        header.style.boxShadow = 'none';
      }
    });
  </script>

</body>

</html>