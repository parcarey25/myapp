<?php
session_start();
$isAuthed = isset($_SESSION['user_id']);

$heroSlides = [
    'photo/landing.jpg',
    'photo/gallery1.jpg',
    'photo/gallery2.jpg'
];

$goalCards = [
    [
        'title' => 'Leaner',
        'desc'  => 'Sculpt a defined, leaner physique through guided training and consistent routines.'
    ],
    [
        'title' => 'Well-Being',
        'desc'  => 'Build confidence, improve energy, and create healthier habits you can keep long-term.'
    ],
    [
        'title' => 'Athletic',
        'desc'  => 'Boost endurance, movement, and performance with sport-focused workouts and conditioning.'
    ],
    [
        'title' => 'Stronger',
        'desc'  => 'Develop real strength for daily life, athletic progress, and long-term body confidence.'
    ],
];

$plans = [
    [
        'name' => 'Body Building',
        'subtitle' => 'with trainer',
        'price' => 'PHP 3,000.00',
        'valid' => 'valid 30 days'
    ],
    [
        'name' => 'Boxing',
        'subtitle' => 'all access + 10 sessions with personal trainer',
        'price' => 'PHP 2,850.00',
        'valid' => 'valid 30 days'
    ],
    [
        'name' => 'Muay Thai',
        'subtitle' => 'all access + 10 sessions with personal trainer',
        'price' => 'PHP 2,850.00',
        'valid' => 'valid 30 days'
    ],
    [
        'name' => 'Zumba',
        'subtitle' => 'group class access',
        'price' => 'PHP 1,000.00',
        'valid' => 'valid 30 days'
    ],
];

$faqItems = [
    'What membership plans do you offer?',
    'How much does the membership cost?',
    'Is there a free trial available?',
    'Do you offer student or family discounts?',
    'What payment methods do you accept?',
    'Will I be billed automatically every month?',
    'Can I pause or freeze my membership?',
    'How do I log in to my account?',
    'I forgot my password. What should I do?',
    'Can I use the membership on multiple devices?',
    'What types of workout programs are included?',
    'Are the workouts suitable for beginners?',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>RJL Fitness | Affordable. Accessible. Authentic.</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#050505;
  --bg-2:#090909;
  --white:#ffffff;
  --white-2:#f4f4f4;
  --text:#121212;
  --text-dark:#f8f8f8;
  --muted:#676d76;
  --muted-dark:#bfc5cd;
  --brand:#b30000;
  --brand-2:#e00000;
  --brand-3:#ff3030;
  --line:rgba(0,0,0,.08);
  --line-dark:rgba(255,255,255,.10);
  --panel:#111214;
  --panel-2:#17191d;
  --shadow:0 22px 50px rgba(0,0,0,.14);
  --shadow-dark:0 22px 50px rgba(0,0,0,.34);
  --radius-xl:28px;
  --radius-lg:22px;
  --radius-md:16px;
  --radius-sm:12px;
  --max:1260px;
}

*{box-sizing:border-box; scroll-behavior:smooth;}
html,body{margin:0; padding:0;}
body{
  font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  background:var(--bg);
  color:var(--text);
  overflow-x:hidden;
}
img{max-width:100%; display:block;}
a{text-decoration:none !important;}
button{outline:none;}
.site-wrap{overflow:hidden;}
.container-xl{max-width:var(--max); margin:0 auto; padding:0 24px;}

/* NAVBAR */
.topbar{
  position:sticky;
  top:0;
  z-index:1200;
  background:linear-gradient(90deg, #3b0000 0%, #750000 42%, #c70000 100%);
  border-bottom:1px solid rgba(255,255,255,.10);
}
.nav-shell{
  min-height:82px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
}
.brand{
  display:flex;
  align-items:center;
  gap:14px;
  color:#fff !important;
  min-width:max-content;
}
.brand-logo{
  width:54px;
  height:54px;
  flex:0 0 54px;
  border-radius:14px;
  overflow:hidden;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08);
}
.brand-logo img{width:100%; height:100%; object-fit:cover;}
.brand-copy{display:flex; flex-direction:column; line-height:1.02;}
.brand-copy strong{font-size:1.4rem; font-weight:900; letter-spacing:.02em;}
.brand-copy span{font-size:.72rem; text-transform:uppercase; letter-spacing:.22em; color:rgba(255,255,255,.82); margin-top:4px;}
.nav-links{
  list-style:none;
  margin:0;
  padding:0;
  display:flex;
  align-items:center;
  gap:6px;
  flex-wrap:wrap;
  justify-content:flex-end;
}
.nav-links a{
  color:#fff !important;
  padding:10px 12px;
  border-radius:999px;
  font-size:.94rem;
  transition:.18s ease;
}
.nav-links a:hover{background:rgba(255,255,255,.10);}
.nav-cta{
  background:rgba(0,0,0,.20);
  border:1px solid rgba(255,255,255,.16);
  font-weight:700;
}
.mobile-menu-btn{
  display:none;
  width:46px;
  height:46px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(0,0,0,.18);
  color:#fff;
  align-items:center;
  justify-content:center;
  font-size:24px;
  cursor:pointer;
}
.mobile-drawer{
  position:fixed;
  top:88px;
  right:16px;
  width:290px;
  max-width:calc(100vw - 32px);
  background:rgba(12,12,12,.98);
  border:1px solid rgba(255,255,255,.10);
  border-radius:20px;
  box-shadow:var(--shadow-dark);
  padding:14px;
  z-index:1250;
  display:none;
}
.mobile-drawer.open{display:block;}
.mobile-drawer a{
  display:block;
  padding:12px;
  border-radius:12px;
  color:#fff !important;
}
.mobile-drawer a:hover{background:rgba(255,255,255,.06);}

/* HERO */
.hero{
  position:relative;
  min-height:calc(100vh - 82px);
  background:#050505;
}
.hero-slider{position:absolute; inset:0; overflow:hidden;}
.hero-slide{
  position:absolute;
  inset:0;
  opacity:0;
  transition:opacity .7s ease;
  background-size:cover;
  background-position:center center;
}
.hero-slide.active{opacity:1;}
.hero-slide::before{
  content:"";
  position:absolute;
  inset:0;
  background:linear-gradient(90deg, rgba(0,0,0,.92) 0%, rgba(30,0,0,.74) 35%, rgba(0,0,0,.58) 62%, rgba(0,0,0,.82) 100%);
}
.hero-shell{position:relative; z-index:2; padding:78px 0 86px;}
.hero-grid{
  display:grid;
  grid-template-columns:minmax(0, 1.02fr) minmax(320px, .74fr);
  gap:28px;
  align-items:center;
  min-height:calc(100vh - 82px - 164px);
}
.hero-copy{max-width:760px; color:#fff;}
.hero-tags{display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px;}
.hero-tag{
  padding:9px 14px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(14,14,14,.64);
  color:#f4f4f4;
  font-size:.92rem;
}
.hero-kicker{
  display:inline-block;
  margin-bottom:14px;
  color:#f2cfcf;
  font-size:.82rem;
  text-transform:uppercase;
  letter-spacing:.18em;
  font-weight:700;
}
.hero h1{
  margin:0;
  font-size:clamp(2.9rem, 5vw, 5.35rem);
  line-height:.97;
  font-weight:900;
  letter-spacing:-.05em;
  max-width:900px;
}
.hero p{
  margin:18px 0 0;
  max-width:720px;
  color:#f0f0f0;
  font-size:1.08rem;
  line-height:1.78;
}
.hero-actions{
  display:flex;
  flex-wrap:wrap;
  gap:14px;
  margin-top:26px;
}
.btn-main,
.btn-ghost,
.btn-light{
  min-height:56px;
  padding:0 24px;
  border-radius:14px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-weight:800;
  font-size:1rem;
  transition:.18s ease;
  border:1px solid transparent;
}
.btn-main{
  color:#fff !important;
  background:linear-gradient(135deg, var(--brand), var(--brand-3));
  box-shadow:0 16px 34px rgba(179,0,0,.34);
}
.btn-main:hover{transform:translateY(-2px); color:#fff !important;}
.btn-ghost{
  color:#fff !important;
  background:rgba(13,13,13,.55);
  border-color:rgba(255,255,255,.16);
}
.btn-ghost:hover{background:rgba(255,255,255,.09); color:#fff !important;}
.btn-light{
  color:#111 !important;
  background:#fff;
  border-color:rgba(0,0,0,.06);
}
.btn-light:hover{transform:translateY(-2px); color:#111 !important;}
.hero-panel{
  width:100%;
  max-width:420px;
  justify-self:end;
  background:linear-gradient(180deg, rgba(17,18,22,.94), rgba(11,12,14,.94));
  border:1px solid rgba(255,255,255,.10);
  border-radius:26px;
  padding:22px;
  color:#fff;
  box-shadow:var(--shadow-dark);
  backdrop-filter:blur(8px);
}
.hero-panel-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:16px;
}
.hero-panel-title h3{
  margin:0;
  font-size:1rem;
  font-weight:800;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:#e4c3a6;
}
.hero-stats{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
  margin-bottom:14px;
}
.hero-stat{
  padding:14px;
  border-radius:16px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
}
.hero-stat small{
  display:block;
  margin-bottom:6px;
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  color:#d8bda2;
}
.hero-stat strong{
  display:block;
  font-size:1.08rem;
}
.hero-list{
  list-style:none;
  margin:0;
  padding:0;
  display:grid;
  gap:10px;
}
.hero-list li{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:12px 14px;
  border-radius:14px;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06);
  font-size:.92rem;
}
.hero-list span:last-child{color:#f3f3f3;}
.hero-dots{
  position:absolute;
  bottom:26px;
  left:50%;
  transform:translateX(-50%);
  z-index:3;
  display:flex;
  gap:10px;
}
.hero-dot{
  width:11px;
  height:11px;
  border-radius:999px;
  border:none;
  background:rgba(255,255,255,.34);
  cursor:pointer;
}
.hero-dot.active{background:#fff;}

/* GLOBAL SECTION */
.section{padding:84px 0;}
.section-light{background:#f5f5f5; color:#121212;}
.section-dark{background:#050505; color:#fff;}
.section-title{max-width:800px; margin-bottom:28px;}
.section-kicker{
  display:inline-block;
  margin-bottom:10px;
  font-size:.78rem;
  letter-spacing:.18em;
  text-transform:uppercase;
  font-weight:700;
  color:#d18f8f;
}
.section-light .section-kicker{color:#b30000;}
.section-title h2{
  margin:0 0 12px;
  font-size:clamp(2rem, 3vw, 3rem);
  font-weight:900;
  letter-spacing:-.03em;
}
.section-title p{
  margin:0;
  font-size:1.03rem;
  line-height:1.8;
  color:inherit;
  opacity:.82;
}

/* GOALS */
.goals-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:20px;
}
.goal-card{
  position:relative;
  background:#fff;
  border:1px solid rgba(0,0,0,.07);
  border-radius:22px;
  overflow:hidden;
  box-shadow:var(--shadow);
}
.goal-photo{
  height:210px;
  position:relative;
  overflow:hidden;
  background:linear-gradient(135deg, #2a2a2a, #8f8f8f);
  clip-path:polygon(10% 0, 100% 0, 88% 100%, 0 100%);
}
.goal-photo img{
  width:100%;
  height:100%;
  object-fit:cover;
}
.goal-body{
  margin-top:-24px;
  padding:18px 18px 22px;
}
.goal-inner{
  background:#ededed;
  color:#111;
  border-radius:18px;
  padding:22px 18px;
  min-height:210px;
  clip-path:polygon(10% 0, 100% 0, 88% 100%, 0 100%);
}
.goal-body h4{
  margin:0 0 14px;
  font-size:1.55rem;
  font-weight:900;
  text-transform:uppercase;
}
.goal-body p{
  margin:0;
  font-size:.95rem;
  line-height:1.75;
  font-weight:600;
}

/* ABOUT STRIP */
.about-strip{
  display:grid;
  grid-template-columns:minmax(0, 1.05fr) minmax(300px, .95fr);
  gap:20px;
  align-items:stretch;
}
.about-card,
.improve-card{
  border-radius:24px;
  padding:26px;
  box-shadow:var(--shadow-dark);
  border:1px solid rgba(255,255,255,.08);
  background:linear-gradient(180deg, rgba(17,18,22,.96), rgba(10,10,12,.96));
}
.about-card p,
.improve-card p{
  margin:0;
  color:#d5d9df;
  line-height:1.85;
}
.improve-list{margin:16px 0 0; padding-left:18px; color:#f0f0f0;}
.improve-list li{margin-bottom:10px;}

/* PLANS */
.plans-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:18px;
}
.plan-card{
  position:relative;
  background:#fff;
  border:2px solid rgba(0,0,0,.10);
  border-radius:24px;
  overflow:hidden;
  box-shadow:var(--shadow);
  text-align:center;
  padding-bottom:18px;
}
.plan-head{
  position:relative;
  min-height:165px;
  display:flex;
  align-items:flex-start;
  justify-content:center;
  padding-top:18px;
}
.plan-half{
  position:absolute;
  left:12%;
  right:12%;
  top:14px;
  height:140px;
  border-radius:0 0 999px 999px;
  background:#d44b5a;
}
.plan-logo{
  position:relative;
  z-index:2;
  width:62px;
  height:32px;
  object-fit:contain;
  margin-top:4px;
}
.plan-copy{
  position:relative;
  z-index:2;
  margin-top:30px;
  padding:0 18px;
}
.plan-copy h4{
  margin:0;
  font-size:1.85rem;
  font-weight:900;
  line-height:1.02;
}
.plan-copy p{
  margin:8px 0 0;
  font-size:.9rem;
  line-height:1.35;
  color:#111;
  font-weight:700;
}
.plan-price{
  margin:2px 0 2px;
  color:#d10000;
  font-size:1.9rem;
  font-weight:900;
  line-height:1.1;
}
.plan-valid{
  color:#444;
  font-size:.92rem;
  font-weight:700;
}
.plan-actions{padding:0 18px; margin-top:16px;}
.plan-actions a{width:100%;}

/* PROGRAMS */
.program-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:16px;
}
.program-card{
  padding:24px;
  border-radius:22px;
  background:linear-gradient(180deg, #121317 0%, #0a0a0d 100%);
  border:1px solid rgba(255,255,255,.08);
  box-shadow:var(--shadow-dark);
}
.program-icon{
  width:58px;
  height:58px;
  border-radius:18px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg, rgba(255,255,255,.12), rgba(179,0,0,.24));
  border:1px solid rgba(255,255,255,.08);
  font-size:1.35rem;
  margin-bottom:14px;
}
.program-card h4{margin:0 0 10px; font-size:1.15rem; font-weight:800;}
.program-card p{margin:0; color:#d4d8de; line-height:1.75; font-size:.95rem;}

/* TESTIMONIALS */
.testi-grid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:16px;
}
.testi-card{
  padding:24px;
  border-radius:22px;
  background:#fff;
  border:1px solid rgba(0,0,0,.08);
  box-shadow:var(--shadow);
}
.testi-card p{margin:0 0 16px; line-height:1.86; color:#2b2b2b;}
.testi-meta strong{display:block; font-size:1rem;}
.testi-meta span{display:block; color:#666; font-size:.9rem; margin-top:3px;}

/* FAQ */
.faq-wrap{
  background:#fff;
  border:1px solid rgba(0,0,0,.08);
  border-radius:28px;
  box-shadow:var(--shadow);
  padding:24px;
}
.faq-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:16px 20px;
}
.faq-item{
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:14px 8px;
  border-bottom:1px solid rgba(0,0,0,.07);
}
.faq-number{
  width:44px;
  height:44px;
  flex:0 0 44px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,.18);
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:800;
}
.faq-item p{
  margin:6px 0 0;
  font-size:1.05rem;
  line-height:1.55;
  font-weight:700;
}

/* CTA */
.cta-strip{
  padding:30px;
  border-radius:28px;
  background:linear-gradient(90deg, rgba(70,0,0,.96), rgba(179,0,0,.96));
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:24px;
  box-shadow:var(--shadow-dark);
}
.cta-strip h3{margin:0 0 8px; font-size:1.95rem; font-weight:900; letter-spacing:-.03em;}
.cta-strip p{margin:0; color:#f4ecec; line-height:1.7; max-width:700px;}
.cta-actions{display:flex; flex-wrap:wrap; gap:12px; min-width:max-content;}

/* FLOAT PANEL */
.float-wrap{
  position:fixed;
  right:20px;
  top:50%;
  transform:translateY(-50%);
  z-index:1300;
}
.float-btn{
  width:60px;
  height:60px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:linear-gradient(135deg, var(--brand), var(--brand-3));
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:28px;
  box-shadow:0 16px 34px rgba(179,0,0,.32);
  cursor:pointer;
}
.side-panel{
  position:fixed;
  top:50%;
  right:20px;
  transform:translate(115%, -50%);
  width:430px;
  max-width:calc(100vw - 28px);
  background:linear-gradient(180deg, rgba(17,18,22,.98), rgba(10,10,12,.98));
  border:1px solid rgba(255,255,255,.10);
  border-radius:24px;
  box-shadow:var(--shadow-dark);
  transition:transform .32s ease;
  z-index:1310;
  overflow:hidden;
  color:#fff;
}
.side-panel.open{transform:translate(0, -50%);}
.panel-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:16px 18px;
  border-bottom:1px solid rgba(255,255,255,.08);
}
.panel-header h5{margin:0; font-weight:900; font-size:1.08rem;}
.close-x{
  border:none;
  background:transparent;
  color:#aaa;
  font-size:28px;
  line-height:1;
  cursor:pointer;
}
.close-x:hover{color:#fff;}
.panel-body{padding:18px;}
.panel-tabs{display:flex; gap:8px; margin-bottom:14px;}
.tab-btn{
  flex:1;
  min-height:46px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.10);
  background:#17181b;
  color:#eee;
  font-weight:700;
  cursor:pointer;
}
.tab-btn.active{
  background:rgba(179,0,0,.18);
  border-color:rgba(255,48,48,.40);
}
.panel section{display:none;}
.panel section.active{display:block;}
.form-control{
  min-height:48px;
  border-radius:12px;
  background:#111214;
  border:1px solid rgba(255,255,255,.10);
  color:#eee;
}
.form-control:focus{
  background:#111214;
  color:#fff;
  box-shadow:none;
  border-color:rgba(255,48,48,.40);
}
.helper{color:#aeb4bd; font-size:.92rem; line-height:1.65;}
.backdrop{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.48);
  display:none;
  z-index:1305;
}
.backdrop.show{display:block;}

footer{
  padding:28px 24px 34px;
  text-align:center;
  background:#050505;
  color:#aeb4bd;
  font-size:.93rem;
}

/* RESPONSIVE */
@media (max-width: 1199.98px){
  .goals-grid,
  .plans-grid,
  .program-grid{grid-template-columns:repeat(2, minmax(0,1fr));}
  .hero-grid{grid-template-columns:minmax(0,1fr) 360px;}
}
@media (max-width: 991.98px){
  .desktop-nav{display:none;}
  .mobile-menu-btn{display:inline-flex;}
  .hero{min-height:auto;}
  .hero-shell{padding:56px 0 78px;}
  .hero-grid,
  .about-strip,
  .cta-strip,
  .faq-grid,
  .testi-grid{grid-template-columns:1fr; display:grid;}
  .hero-panel{justify-self:start; max-width:100%;}
}
@media (max-width: 767.98px){
  .container-xl{padding:0 18px;}
  .brand-copy strong{font-size:1.16rem;}
  .brand-copy span{font-size:.66rem;}
  .hero h1{font-size:clamp(2.35rem, 10vw, 4rem);}
  .hero p{font-size:1rem;}
  .goals-grid,
  .plans-grid,
  .program-grid,
  .faq-grid{grid-template-columns:1fr;}
  .float-wrap{right:14px;}
  .float-btn{width:54px; height:54px; font-size:24px;}
  .side-panel{right:14px; width:min(430px, calc(100vw - 18px));}
}
@media (max-width: 575.98px){
  .nav-shell{min-height:74px;}
  .brand-logo{width:46px; height:46px; flex-basis:46px;}
  .hero-actions{flex-direction:column; align-items:stretch;}
  .btn-main,.btn-ghost,.btn-light{width:100%;}
  .hero-stats{grid-template-columns:1fr;}
  .goal-photo{height:190px;}
  .goal-inner{min-height:0;}
  .cta-strip{padding:22px;}
  .cta-actions{min-width:0; width:100%;}
  .cta-actions a{width:100%;}
}
</style>
</head>
<body>
<div class="site-wrap">

  <nav class="topbar">
    <div class="container-xl nav-shell">
      <a class="brand" href="#top">
        <span class="brand-logo"><img src="photo/logo.jpg" alt="RJL Fitness"></span>
        <span class="brand-copy">
          <strong>RJL Fitness</strong>
          <span>Power Fitness Center</span>
        </span>
      </a>

      <ul class="nav-links desktop-nav">
        <li><a href="#about">About</a></li>
        <li><a href="#plans">Membership</a></li>
        <li><a href="#programs">Classes</a></li>
        <li><a href="#faq">FAQs</a></li>
        <li><a href="#gallery">Gallery</a></li>
        <?php if ($isAuthed): ?>
          <li><a class="nav-cta" href="home.php">Dashboard</a></li>
        <?php else: ?>
          <li><a class="nav-cta" href="register.php">Join Online</a></li>
        <?php endif; ?>
      </ul>

      <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">☰</button>
    </div>
  </nav>

  <div class="mobile-drawer" id="mobileDrawer">
    <a href="#about">About</a>
    <a href="#plans">Membership</a>
    <a href="#programs">Classes</a>
    <a href="#faq">FAQs</a>
    <a href="#gallery">Gallery</a>
    <?php if ($isAuthed): ?>
      <a href="home.php">Dashboard</a>
    <?php else: ?>
      <a href="register.php">Join Online</a>
    <?php endif; ?>
  </div>

  <header class="hero" id="top">
    <div class="hero-slider">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>" style="background-image:url('<?= htmlspecialchars($slide, ENT_QUOTES, 'UTF-8') ?>');"></div>
      <?php endforeach; ?>
    </div>

    <div class="container-xl hero-shell">
      <div class="hero-grid">
        <div class="hero-copy">
          <div class="hero-tags">
            <span class="hero-tag">Open Daily</span>
            <span class="hero-tag">Flexible Plans</span>
            <span class="hero-tag">Easy Booking</span>
          </div>
          <span class="hero-kicker">Affordable. Accessible. Authentic.</span>
          <h1>Train with purpose at RJL Power Fitness Center.</h1>
          <p>
            From first-time lifters to seasoned athletes, RJL Fitness is your space to grow.
            Book classes, manage your plan, and stay consistent with a modern gym experience designed to feel simple and motivating.
          </p>
          <div class="hero-actions">
            <a href="#plans" class="btn-main">See Pricing</a>
            <a href="#programs" class="btn-ghost">Explore Classes</a>
          </div>
        </div>

        <aside class="hero-panel">
          <div class="hero-panel-title">
            <h3>Member Highlights</h3>
          </div>
          <div class="hero-stats">
            <div class="hero-stat">
              <small>Programs</small>
              <strong>4 Core Tracks</strong>
            </div>
            <div class="hero-stat">
              <small>Booking</small>
              <strong>Fast &amp; Easy</strong>
            </div>
          </div>
          <ul class="hero-list">
            <li><span>Body Building</span><span>Strength-focused</span></li>
            <li><span>Boxing</span><span>Power and skill</span></li>
            <li><span>Muay Thai</span><span>Technique and grit</span></li>
            <li><span>Zumba</span><span>Fun cardio</span></li>
          </ul>
        </aside>
      </div>
    </div>

    <div class="hero-dots" id="heroDots">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <button class="hero-dot<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>" aria-label="Show slide <?= $i + 1 ?>"></button>
      <?php endforeach; ?>
    </div>
  </header>

  <section class="section section-light">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">Your Fitness Goals</span>
        <h2>Train for the result you want most.</h2>
        <p>
          I adjusted this section to match the look from your prototype more closely, then added cleaner spacing,
          stronger typography, and better mobile responsiveness so it feels more premium and easier to scan.
        </p>
      </div>

      <div class="goals-grid">
        <?php foreach ($goalCards as $index => $goal): ?>
          <article class="goal-card">
            <div class="goal-photo">
              <img src="photo/gallery<?= ($index % 8) + 1 ?>.jpg" alt="<?= htmlspecialchars($goal['title'], ENT_QUOTES, 'UTF-8') ?> goal">
            </div>
            <div class="goal-body">
              <div class="goal-inner">
                <h4><?= htmlspecialchars($goal['title'], ENT_QUOTES, 'UTF-8') ?></h4>
                <p><?= htmlspecialchars($goal['desc'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section section-dark" id="about">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">About RJL Fitness</span>
        <h2>A modern gym landing page based on your prototype, with extra improvements.</h2>
        <p>
          I kept the black, red, and white identity you want, but I also added a rotating hero, a cleaner membership section,
          testimonials, and stronger call-to-action areas so the page feels more complete and conversion-ready.
        </p>
      </div>

      <div class="about-strip">
        <div class="about-card">
          <p>
            RJL Fitness is built for people who want clear pricing, approachable training options, and a gym environment that feels motivating instead of intimidating.
            Whether your goal is weight loss, conditioning, skill training, or strength, this layout is designed to help visitors understand the value of your gym quickly.
          </p>
        </div>
        <div class="improve-card">
          <p><strong>Suggestions I added to improve the page:</strong></p>
          <ul class="improve-list">
            <li>Hero image slider to make the first screen feel more alive</li>
            <li>More visual membership cards inspired by your prototype</li>
            <li>Testimonials to build trust for new visitors</li>
            <li>A better FAQ area for common questions</li>
            <li>Stronger CTA buttons to increase sign-ups</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <section class="section section-light" id="plans">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">Membership</span>
        <h2>Simple plans with a look inspired by your prototype.</h2>
        <p>
          I redesigned these cards to feel closer to the video you sent, especially the top curved red area and cleaner pricing focus.
        </p>
      </div>

      <div class="plans-grid">
        <?php foreach ($plans as $plan): ?>
          <article class="plan-card">
            <div class="plan-head">
              <div class="plan-half"></div>
              <img class="plan-logo" src="photo/logo.jpg" alt="RJL Fitness logo">
              <div class="plan-copy">
                <h4><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                <p><?= htmlspecialchars($plan['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
            </div>
            <div class="plan-price"><?= htmlspecialchars($plan['price'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="plan-valid"><?= htmlspecialchars($plan['valid'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="plan-actions">
              <?php if ($isAuthed): ?>
                <a href="home.php" class="btn-main">Choose Plan</a>
              <?php else: ?>
                <a href="register.php" class="btn-main">Choose Plan</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section section-dark" id="programs">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">Classes</span>
        <h2>Core training options for every kind of member.</h2>
        <p>
          I kept this section because it helps first-time visitors understand what they can actually do inside the gym.
        </p>
      </div>

      <div class="program-grid">
        <article class="program-card">
          <div class="program-icon">🏋️</div>
          <h4>Body Building</h4>
          <p>Train for strength, muscle development, and better lifting confidence with a more structured routine.</p>
        </article>
        <article class="program-card">
          <div class="program-icon">🥊</div>
          <h4>Boxing</h4>
          <p>Improve power, coordination, endurance, and technique through focused boxing sessions.</p>
        </article>
        <article class="program-card">
          <div class="program-icon">🔥</div>
          <h4>Muay Thai</h4>
          <p>Build conditioning, discipline, and striking technique with a more athletic training format.</p>
        </article>
        <article class="program-card">
          <div class="program-icon">💃</div>
          <h4>Zumba</h4>
          <p>Keep movement fun and engaging while improving energy, rhythm, and cardiovascular health.</p>
        </article>
      </div>
    </div>
  </section>

  <section class="section section-light">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">Testimonials</span>
        <h2>Added trust-building content to improve conversions.</h2>
        <p>
          This was not strongly visible in the prototype, but I recommend it because it helps convince new visitors to join.
        </p>
      </div>

      <div class="testi-grid">
        <article class="testi-card">
          <p>“The gym feels approachable, the staff are helpful, and the plans are easier to understand than most gyms I checked.”</p>
          <div class="testi-meta">
            <strong>Angela R.</strong>
            <span>Member</span>
          </div>
        </article>
        <article class="testi-card">
          <p>“I like that I can train, ask for guidance, and keep my routine without feeling pressured by confusing packages.”</p>
          <div class="testi-meta">
            <strong>Mark J.</strong>
            <span>Boxing Program</span>
          </div>
        </article>
        <article class="testi-card">
          <p>“The design now feels more premium and modern, which matches the gym better and makes the website look more trustworthy.”</p>
          <div class="testi-meta">
            <strong>Prototype Suggestion</strong>
            <span>Recommended improvement</span>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="section section-light" id="faq" style="padding-top:10px;">
    <div class="container-xl">
      <div class="section-title">
        <span class="section-kicker">FAQs</span>
        <h2>A cleaner FAQ section inspired by the layout in your video.</h2>
        <p>
          I kept the two-column question layout style because it looks organized and helps users find common answers fast.
        </p>
      </div>

      <div class="faq-wrap">
        <div class="faq-grid">
          <?php foreach ($faqItems as $i => $item): ?>
            <div class="faq-item">
              <div class="faq-number"><?= $i + 1 ?></div>
              <p><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="section section-dark" id="gallery">
    <div class="container-xl">
      <div class="cta-strip">
        <div>
          <h3>Ready to train with RJL Fitness?</h3>
          <p>Book your membership, explore our classes, and start building your routine with a gym that feels affordable, accessible, and authentic.</p>
        </div>
        <div class="cta-actions">
          <?php if ($isAuthed): ?>
            <a href="home.php" class="btn-light">Open Dashboard</a>
          <?php else: ?>
            <a href="register.php" class="btn-light">Join Online</a>
          <?php endif; ?>
          <a href="#plans" class="btn-ghost">See Plans</a>
        </div>
      </div>
    </div>
  </section>

  <div class="float-wrap">
    <button class="float-btn" id="openPanelBtn" title="Open login or register">☰</button>
  </div>

  <div class="side-panel panel" id="sidePanel" aria-hidden="true">
    <div class="panel-header">
      <h5>Welcome to RJL</h5>
      <button class="close-x" id="closePanelBtn" aria-label="Close">×</button>
    </div>
    <div class="panel-body">
      <div class="panel-tabs">
        <button class="tab-btn active" data-tab="loginTab">Log In</button>
        <button class="tab-btn" data-tab="registerTab">Register</button>
      </div>

      <section id="loginTab" class="active">
        <?php if ($isAuthed): ?>
          <div class="alert alert-success">You are already signed in.</div>
          <a href="home.php" class="btn-main" style="width:100%;">Go to Dashboard</a>
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
            <button class="btn-main" style="width:100%; border:none;">Log In</button>
            <p class="helper mt-3 mb-0">Forgot your password? Please ask staff to help reset it.</p>
          </form>
        <?php endif; ?>
      </section>

      <section id="registerTab">
        <div class="mb-3" style="color:#ddd; line-height:1.7;">
          Create your account to start booking sessions, exploring facilities, and choosing the best plan for your goals.
        </div>
        <a href="register.php" class="btn-ghost" style="width:100%; justify-content:center; margin-bottom:12px;">Go to Registration</a>
        <div class="helper">Need help choosing a plan? Start with the membership section above.</div>
      </section>
    </div>
  </div>

  <div class="backdrop" id="backdrop"></div>

  <footer>
    © <?= date('Y') ?> RJL Fitness. All rights reserved.
  </footer>
</div>

<script>
const slides = document.querySelectorAll('.hero-slide');
const dots = document.querySelectorAll('.hero-dot');
let currentSlide = 0;
let slideTimer = null;

function showSlide(index){
  slides.forEach((slide, i) => slide.classList.toggle('active', i === index));
  dots.forEach((dot, i) => dot.classList.toggle('active', i === index));
  currentSlide = index;
}

function nextSlide(){
  if (!slides.length) return;
  const next = (currentSlide + 1) % slides.length;
  showSlide(next);
}

function startSlider(){
  if (slides.length < 2) return;
  clearInterval(slideTimer);
  slideTimer = setInterval(nextSlide, 4500);
}

dots.forEach(dot => {
  dot.addEventListener('click', function(){
    const index = parseInt(this.dataset.slide || '0', 10);
    showSlide(index);
    startSlider();
  });
});

showSlide(0);
startSlider();

const openBtn = document.getElementById('openPanelBtn');
const closeBtn = document.getElementById('closePanelBtn');
const panel = document.getElementById('sidePanel');
const backdrop = document.getElementById('backdrop');
const tabs = document.querySelectorAll('.tab-btn');
const sections = {
  loginTab: document.getElementById('loginTab'),
  registerTab: document.getElementById('registerTab')
};

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

openBtn?.addEventListener('click', openPanel);
closeBtn?.addEventListener('click', closePanel);
backdrop?.addEventListener('click', closePanel);

document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closePanel();
});

tabs.forEach(function(btn){
  btn.addEventListener('click', function(){
    tabs.forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');
    Object.values(sections).forEach(function(s){ s.classList.remove('active'); });
    const id = btn.dataset.tab;
    if (sections[id]) sections[id].classList.add('active');
  });
});

const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const mobileDrawer = document.getElementById('mobileDrawer');

mobileMenuBtn?.addEventListener('click', function(){
  mobileDrawer.classList.toggle('open');
});

document.addEventListener('click', function(e){
  if (!mobileDrawer) return;
  if (mobileMenuBtn?.contains(e.target)) return;
  if (mobileDrawer.contains(e.target)) return;
  mobileDrawer.classList.remove('open');
});
</script>
</body>
</html>