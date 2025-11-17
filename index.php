<?php
// index.php — Landing page with larger hero, About, Vision & Mission, Gallery, and floating Login/Register panel
session_start();
$isAuthed = isset($_SESSION['user_id']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RJL Fitness | Welcome</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --bg:#0d0d0d; --panel:#151515; --line:#252525; --muted:#a9a9a9;
    --brand:#b30000; --brand-2:#ff1a1a;
  }
  *{box-sizing:border-box; scroll-behavior:smooth;}
  body{margin:0; background:var(--bg); color:#fff; font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}

  /* Nav */
  .navbar{background:linear-gradient(90deg,#000,var(--brand)); border-bottom:1px solid #000;}
  .navbar .brand{font-weight:800; letter-spacing:.6px; color:#fff !important; text-decoration:none; font-size:1.25rem;}
  .nav-link{color:#eee !important;}
  .nav-btn{margin-left:.5rem}

  /* HERO — bigger header */
  .hero{
    position:relative; min-height:88vh; display:flex; align-items:center; overflow:hidden; background:#000;
  }
  .hero::before{
    content:""; position:absolute; inset:0;
    background:url('photo/landing.jpg') center/cover no-repeat; /* change to your hero image */
    filter:brightness(.52);
  }
  .hero::after{
    content:""; position:absolute; inset:0;
    background:radial-gradient(60% 60% at 70% 40%, rgba(255,0,0,.18) 0%, rgba(0,0,0,0) 60%);
  }
  .hero-wrap{position:relative; z-index:1; width:100%;}
  .tag{
    display:inline-block; padding:8px 12px; border:1px solid #3a3a3a; border-radius:999px;
    color:#eee; background:#161616; margin-right:10px; margin-bottom:12px; font-size:.95rem;
  }
  .hero h1{font-weight:900; font-size:3rem; line-height:1.15; margin:10px 0 10px;}
  .hero p{color:#e6e6e6; max-width:840px; margin-bottom:22px; font-size:1.1rem;}
  .cta .btn{margin-right:12px; margin-bottom:12px;}
  .btn-danger{background:var(--brand); border:none;}
  .btn-danger:hover{background:var(--brand-2);}
  .btn-outline-light{border-color:#444; color:#eee;}
  .btn-outline-light:hover{background:#1f1f1f; color:#fff;}

  /* Sections */
  .section{padding:64px 0;}
  .section h2{font-weight:800; margin-bottom:16px;}
  .section p.lead{color:#d9d9d9;}
  .card-dark{background:var(--panel); border:1px solid var(--line); border-radius:14px;}
  .feat-icon{font-size:1.6rem; margin-right:10px; color:#ff6b6b}

  /* About block */
  .about-text{font-size:1.05rem; color:#d8d8d8;}
  .about-pill{display:inline-block; padding:6px 10px; background:#171717; border:1px solid #2a2a2a; border-radius:999px; margin-right:8px; margin-bottom:8px;}

  /* Vision & Mission */
  .vm-card{background:#141414; border:1px solid #2a2a2a; border-radius:14px; padding:20px; height:100%;}
  .vm-card h4{font-weight:700; margin-bottom:8px;}
  .vm-card p{color:#cfcfcf; margin-bottom:0;}

  /* Gallery */
  .gallery-grid{
    display:grid; gap:12px;
    grid-template-columns: repeat(4, 1fr);
  }
  .gallery-grid .g{
    position:relative; padding-top:66%; border-radius:12px; overflow:hidden; border:1px solid #2a2a2a; background:#0f0f0f;
  }
  .gallery-grid .g img{
    position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition: transform .35s ease, filter .35s ease;
  }
  .gallery-grid .g:hover img{ transform:scale(1.05); filter:brightness(1.05); }
  @media (max-width: 992px){ .gallery-grid{grid-template-columns: repeat(2, 1fr);} }
  @media (max-width: 576px){ .gallery-grid{grid-template-columns: 1fr;} }

  /* Floating side button + panel */
  .float-wrap{
    position:fixed; right:18px; top:50%; transform:translateY(-50%);
    z-index:1060; display:flex; flex-direction:column; align-items:flex-end; gap:10px;
  }
  .float-btn{
    width:52px; height:52px; border-radius:999px; background:var(--brand);
    border:none; color:#fff; font-size:22px; display:flex; align-items:center; justify-content:center;
    box-shadow:0 10px 30px rgba(0,0,0,.35); cursor:pointer;
  }
  .float-btn:hover{ background:var(--brand-2); }

  .side-panel{
    position:fixed; top:50%; right:18px; transform:translate(110%, -50%); /* hidden off-screen */
    background:var(--panel); border:1px solid var(--line); border-radius:16px;
    width:420px; max-width:92vw; box-shadow:0 20px 55px rgba(0,0,0,.55);
    transition:transform .35s ease; z-index:1065; overflow:hidden;
  }
  .side-panel.open{ transform:translate(0, -50%); }
  .panel-header{display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #2a2a2a;}
  .panel-header h5{margin:0; font-weight:800;}
  .panel-body{padding:16px;}
  .close-x{background:transparent; border:none; color:#aaa; font-size:28px; line-height:1; cursor:pointer;}
  .close-x:hover{color:#fff;}
  .panel-tabs{display:flex; gap:8px; margin-bottom:12px;}
  .tab-btn{
    flex:1; padding:10px 12px; border:1px solid #333; border-radius:10px; background:#161616; color:#eee; cursor:pointer; font-weight:600;
  }
  .tab-btn.active{ border-color:#ff3a3a; background:#221414; }
  .panel section{display:none;}
  .panel section.active{display:block;}
  .form-control{background:#121212; border:1px solid #2a2a2a; color:#eee;}
  .helper{color:#a9a9a9; font-size:.9rem}

  /* Backdrop */
  .backdrop{
    position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1062; display:none;
  }
  .backdrop.show{ display:block; }

  footer{color:#8f8f8f; font-size:.9rem; text-align:center; padding:22px;}
</style>
</head>
<body>

<!-- NAV -->
<nav class="navbar navbar-dark">
  <div class="container d-flex align-items-center">
    <a class="brand" href="#top">RJL Fitness</a>
    <ul class="navbar-nav flex-row ml-auto">
      <li class="nav-item mx-2"><a class="nav-link" href="#about">About</a></li>
      <li class="nav-item mx-2"><a class="nav-link" href="#vision">Vision & Mission</a></li>
      <li class="nav-item mx-2"><a class="nav-link" href="#gallery">Gallery</a></li>
      <li class="nav-item mx-2"><a class="nav-link" href="pricing.php">Pricing</a></li>
      <?php if ($isAuthed): ?>
        <li class="nav-item mx-2"><a class="btn btn-danger btn-sm nav-btn" href="home.php">Dashboard</a></li>
      <?php else: ?>
        <li class="nav-item mx-2"><a class="btn btn-danger btn-sm nav-btn" href="login.php">Log In</a></li>
      <?php endif; ?>
    </ul>
  </div>
</nav>

<!-- HERO (BIGGER HEADER) -->
<header class="hero" id="top">
  <div class="container hero-wrap">
    <span class="tag">Open Daily</span>
    <span class="tag">Flexible Plans</span>
    <span class="tag">Easy Booking</span>
    <h1>Affordable. Accessible. Authentic.</h1>
    <p>From first-time lifters to seasoned athletes, RJL Fitness is your space to grow. Book classes, manage your plan, and stay consistent—without the pressure of long-term lock-ins.</p>
    <div class="cta">
      <a href="pricing.php" class="btn btn-danger btn-lg">See Pricing</a>
      <a href="facilities.php" class="btn btn-outline-light btn-lg">Explore Facilities</a>
    </div>
  </div>
</header>

<!-- ABOUT -->
<section class="section" id="about">
  <div class="container">
    <h2>About RJL Fitness</h2>
    <p class="lead">How we started—and where we’re headed.</p>

    <div class="row">
      <div class="col-lg-7">
        <div class="card-dark p-4 mb-3">
          <p class="about-text">
            RJL Fitness began as a small community space with one promise: make high-quality training
            accessible to everyone. We focused on the essentials—solid equipment, skilled coaches, and
            a welcoming culture. Today, we offer Boxing, Muay Thai, Zumba, and Bodybuilding sessions,
            with an easy online booking experience and flexible membership options.
          </p>
          <div class="mt-2">
            <span class="about-pill">Community-Driven</span>
            <span class="about-pill">Coach-Led Sessions</span>
            <span class="about-pill">No Lock-Ins</span>
            <span class="about-pill">Transparent Pricing</span>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card-dark p-4 mb-3">
          <div class="d-flex align-items-start">
            <div class="feat-icon">📅</div>
            <div>
              <h5 class="mb-1">Simple Scheduling</h5>
              <p class="mb-0" style="color:#ddd">Reserve a spot in seconds from your phone or PC.</p>
            </div>
          </div>
          <hr style="border-color:#2a2a2a">
          <div class="d-flex align-items-start">
            <div class="feat-icon">💳</div>
            <div>
              <h5 class="mb-1">Flexible Plans</h5>
              <p class="mb-0" style="color:#ddd">Day passes, monthly, or bundles—pay only for what you need.</p>
            </div>
          </div>
          <hr style="border-color:#2a2a2a">
          <div class="d-flex align-items-start">
            <div class="feat-icon">🏋️</div>
            <div>
              <h5 class="mb-1">Facilities</h5>
              <p class="mb-0" style="color:#ddd">Boxing • Muay Thai • Zumba • Bodybuilding</p>
            </div>
          </div>
          <div class="mt-3">
            <a href="register.php" class="btn btn-danger btn-block">Create Account</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- VISION & MISSION -->
<section class="section" id="vision">
  <div class="container">
    <h2>Vision & Mission</h2>
    <p class="lead">What drives us every day.</p>

    <div class="row">
      <div class="col-md-6 mb-3">
        <div class="vm-card">
          <h4>Vision</h4>
          <p>To be the most welcoming, tech-enabled gym in our community—empowering people to train consistently, confidently, and affordably.</p>
        </div>
      </div>
      <div class="col-md-6 mb-3">
        <div class="vm-card">
          <h4>Mission</h4>
          <p>We provide essential equipment, expert guidance, and flexible memberships—supported by simple online booking—so anyone can start and stay on their fitness journey.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- GALLERY -->
<section class="section" id="gallery">
  <div class="container">
    <h2>Gallery</h2>
    <p class="lead">A glimpse of the RJL experience.</p>

    <div class="gallery-grid">
      <!-- Replace these image paths with your own -->
      <div class="g"><img src="photo/gallery1.jpg" alt="Gym photo 1"></div>
      <div class="g"><img src="photo/gallery2.jpg" alt="Gym photo 2"></div>
      <div class="g"><img src="photo/gallery3.jpg" alt="Gym photo 3"></div>
      <div class="g"><img src="photo/gallery4.jpg" alt="Gym photo 4"></div>
      <div class="g"><img src="photo/gallery5.jpg" alt="Gym photo 5"></div>
      <div class="g"><img src="photo/gallery6.jpg" alt="Gym photo 6"></div>
      <div class="g"><img src="photo/gallery7.jpg" alt="Gym photo 7"></div>
      <div class="g"><img src="photo/gallery8.jpg" alt="Gym photo 8"></div>
    </div>

    <div class="text-center mt-4">
      <a class="btn btn-outline-light" href="facilities.php">See Facilities</a>
      <a class="btn btn-danger ml-2" href="pricing.php">See Pricing</a>
    </div>
  </div>
</section>

<!-- FLOATING BUTTON + PANEL -->
<div class="float-wrap">
  <button class="float-btn" id="openPanelBtn" title="Login / Register">☰</button>

  <div class="side-panel" id="sidePanel" aria-hidden="true">
    <div class="panel-header">
      <h5>Welcome to RJL</h5>
      <button class="close-x" id="closePanelBtn" aria-label="Close">×</button>
    </div>
    <div class="panel-body">
      <div class="panel-tabs">
        <button class="tab-btn active" data-tab="loginTab">Log In</button>
        <button class="tab-btn" data-tab="registerTab">Register</button>
      </div>

      <!-- LOGIN -->
      <section id="loginTab" class="active">
        <?php if ($isAuthed): ?>
          <div class="alert alert-success">You’re already signed in.</div>
          <a href="home.php" class="btn btn-danger btn-block">Go to Dashboard</a>
        <?php else: ?>
          <form method="post" action="login.php" autocomplete="on">
            <div class="form-group">
              <label>Username</label>
              <input class="form-control" name="username" required>
            </div>
            <div class="form-group">
              <label>Password</label>
              <input type="password" class="form-control" name="password" required>
            </div>
            <button class="btn btn-danger btn-block">Log In</button>
            <p class="helper mt-2 mb-0">Forgot your password? Ask staff to reset at the front desk.</p>
          </form>
        <?php endif; ?>
      </section>

      <!-- REGISTER -->
      <section id="registerTab">
        <div class="mb-2" style="color:#ddd">
          Create your account to start booking sessions and manage your plan.
        </div>
        <a href="register.php" class="btn btn-outline-light btn-block mb-2">Go to Registration</a>
        <div class="helper">Need help choosing a plan? <a href="pricing.php">See Pricing</a></div>
      </section>
    </div>
  </div>
</div>

<div class="backdrop" id="backdrop"></div>

<footer>
  © <?= date('Y') ?> RJL Fitness. All rights reserved.
</footer>

<script>
// Panel controls
const openBtn = document.getElementById('openPanelBtn');
const closeBtn= document.getElementById('closePanelBtn');
const panel   = document.getElementById('sidePanel');
const backdrop= document.getElementById('backdrop');
const tabs    = document.querySelectorAll('.tab-btn');
const sections= { loginTab: document.getElementById('loginTab'), registerTab: document.getElementById('registerTab') };

function openPanel(){
  panel.classList.add('open');
  panel.setAttribute('aria-hidden','false');
  backdrop.classList.add('show');
}
function closePanel(){
  panel.classList.remove('open');
  panel.setAttribute('aria-hidden','true');
  backdrop.classList.remove('show');
}

openBtn.addEventListener('click', openPanel);
closeBtn.addEventListener('click', closePanel);
backdrop.addEventListener('click', closePanel);
document.addEventListener('keydown', e => { if(e.key==='Escape') closePanel(); });

// Tabs
tabs.forEach(b=>{
  b.addEventListener('click', ()=>{
    tabs.forEach(t=>t.classList.remove('active'));
    b.classList.add('active');
    Object.values(sections).forEach(s=>s.classList.remove('active'));
    const id=b.dataset.tab; sections[id]?.classList.add('active');
  });
});
</script>
</body>
</html>