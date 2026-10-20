<?php

/**
 * Banner at the top of every page: the MyAnimLab logo (images/logo-mark.svg)
 * and the name of the establishment ($labName, from the settings). Included
 * by header.php and by the pages shown before sign-in.
 */
?>
<style>
    .brand-banner {
        background: radial-gradient(circle at 85% -40%, rgba(95, 211, 199, .28), transparent 55%),
            linear-gradient(115deg, #0e2a3d 0%, #154763 55%, #16706a 100%);
        color: #fff;
    }
    .brand-banner .brand-inner {
        max-width: 1320px;
        margin: 0 auto;
        padding: .8rem 1.25rem;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .5rem 1.5rem;
    }
    .brand-banner a.brand-home {
        display: inline-flex;
        align-items: center;
        gap: .8rem;
        color: #fff;
        text-decoration: none;
    }
    .brand-banner .brand-home img {
        width: 52px;
        height: 52px;
        border-radius: 13px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, .25);
    }
    .brand-banner .brand-name {
        font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        font-size: 1.85rem;
        line-height: 1;
        letter-spacing: -.02em;
    }
    .brand-banner .brand-name b {
        font-weight: 700;
    }
    .brand-banner .brand-name b.brand-accent {
        color: #5fd3c7;
    }
    .brand-banner .brand-lab-name {
        font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
        font-size: 1.15rem;
        font-weight: 500;
        color: rgba(255, 255, 255, .92);
        border-left: 3px solid #5fd3c7;
        padding-left: .75rem;
    }
    .nav-container {
        background-color: #0c2333;
        border-top: 1px solid rgba(255, 255, 255, .08);
        padding: .55rem .5rem;
        text-align: center;
        margin: 0;
    }
    .nav-container .nav {
        row-gap: .4rem;
    }
    .nav-container .nav .btn {
        margin: 0 4px;
    }
    .nav-container .btn-primary {
        background-color: rgba(255, 255, 255, .08);
        border-color: rgba(255, 255, 255, .16);
    }
    .nav-container .btn-primary:hover,
    .nav-container .btn-primary:focus,
    .nav-container .show > .btn-primary {
        background-color: #17a398;
        border-color: #17a398;
    }
    .dropdown-menu {
        min-width: auto;
    }
    @media (max-width: 576px) {
        .brand-banner .brand-inner {
            justify-content: center;
            text-align: center;
        }
        .brand-banner .brand-name {
            font-size: 1.5rem;
        }
        .brand-banner .brand-home img {
            width: 42px;
            height: 42px;
        }
        .brand-banner .brand-lab-name {
            font-size: 1rem;
            border-left: 0;
            padding-left: 0;
        }
    }
</style>
<header class="brand-banner">
    <div class="brand-inner">
        <a class="brand-home" href="home.php" title="MyAnimLab">
            <img src="images/logo-mark.svg" alt="" width="52" height="52">
            <span class="brand-name">My<b>Anim</b><b class="brand-accent">Lab</b></span>
        </a>
        <span class="brand-lab-name"><?= htmlspecialchars($labName ?? 'MyAnimLab') ?></span>
    </div>
</header>
