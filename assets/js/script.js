// ===== INTRO ANIMATION (CORREDOR) =====
window.addEventListener('load', function () {
  setTimeout(function () {
    const intro = document.getElementById('intro');
    if (!intro) return;

    intro.classList.add('hide-intro');
    setTimeout(function () {
      intro.remove();
    }, 600);
  }, 3000);
});

// ===== RESTO DO SITE =====
document.addEventListener('DOMContentLoaded', function () {
  // ===== MENU MOBILE =====
  const mobileToggle = document.getElementById('mobile-toggle');
  const heroNav = document.getElementById('nav-menu');
  if (mobileToggle && heroNav) {
    mobileToggle.addEventListener('click', () => {
      heroNav.classList.toggle('open');
    });
  }

  // ===== ELEMENTOS DE TEXTO =====
  const heroTitleEl = document.getElementById('hero-title');
  const heroSubtitleEl = document.getElementById('hero-subtitle');
  const heroQuoteEl = document.getElementById('hero-quote');

  const heroTexts = [
    {
      title: "Transform Your Body,<br>Transform Your Life.",
      subtitle:
        "Premium online coaching with Rafa Breder, based in the Boston area and training clients all across the United States.",
      quote:
        "Strength starts in your mind before it appears in your body."
    },
    {
      title: "Every Transformation<br>Starts With a Choice.",
      subtitle:
        "The moment you decide to change is the moment your new life begins.",
      quote:
        "The decision to begin is stronger than any excuse."
    },
    {
      title: "Strength Is Built,<br>Not Born.",
      subtitle:
        "With the right plan, structure and accountability, your potential has no ceiling.",
      quote:
        "You don’t find strength — you build it, rep after rep."
    },
    {
      title: "Your Mind Shapes<br>Your Body.",
      subtitle:
        "Training is not only physical — it is mental clarity, focus and resilience applied to your day.",
      quote:
        "A stronger mindset creates a stronger physique."
    },
    {
      title: "Your Best Self<br>Is Within Reach.",
      subtitle:
        "This is more than workouts — it is a lifestyle upgrade designed around your real life.",
      quote:
        "The body you want already exists — it just needs to be revealed."
    }
  ];

  function setHeroContent(index) {
    const data = heroTexts[index] || heroTexts[0];

    // fade-out + leve descida
    if (heroTitleEl) {
      heroTitleEl.style.opacity = 0;
      heroTitleEl.style.transform = "translateY(10px)";
    }
    if (heroSubtitleEl) {
      heroSubtitleEl.style.opacity = 0;
      heroSubtitleEl.style.transform = "translateY(10px)";
    }
    if (heroQuoteEl) {
      heroQuoteEl.style.opacity = 0;
      heroQuoteEl.style.transform = "translateY(10px)";
    }

    // troca o conteúdo e volta suavemente
    setTimeout(() => {
      if (heroTitleEl) {
        heroTitleEl.innerHTML = data.title;
        heroTitleEl.style.opacity = 1;
        heroTitleEl.style.transform = "translateY(0)";
      }
      if (heroSubtitleEl) {
        heroSubtitleEl.textContent = data.subtitle;
        heroSubtitleEl.style.opacity = 1;
        heroSubtitleEl.style.transform = "translateY(0)";
      }
      if (heroQuoteEl) {
        heroQuoteEl.textContent = data.quote;
        heroQuoteEl.style.opacity = 1;
        heroQuoteEl.style.transform = "translateY(0)";
      }
    }, 350);
  }

  // ===== SLIDESHOW DE VÍDEOS + BOLINHAS =====
  const bgVideo = document.getElementById('bg-video');
  const bgSource = document.getElementById('bg-source');
  const heroDots = Array.from(document.querySelectorAll('.hero-dot'));

  const videoList = [
    "images/1.mp4",
    "images/2.mp4",
    "images/3.mp4",
    "images/4.mp4",
    "images/5.mp4"
  ];

  let currentVideo = 0;

  function setActiveDot(index) {
    if (!heroDots.length) return;
    heroDots.forEach((dot, i) => {
      const isActive = i === index;
      dot.classList.toggle('hero-dot-active', isActive);
      if (isActive) {
        dot.style.setProperty("--progress", "0");
      }
    });
  }

  function goToVideo(index) {
    if (!bgVideo || !bgSource) return;

    currentVideo = (index + videoList.length) % videoList.length;

    // inicia crossfade do vídeo
    bgVideo.style.opacity = 0;

    // textos + bolinhas
    setHeroContent(currentVideo);
    setActiveDot(currentVideo);

    // depois do fade-out troca o src
    setTimeout(() => {
      bgSource.src = videoList[currentVideo];
      bgVideo.load();

      // autoplay pode ser bloqueado: não quebra a página
      bgVideo.play().catch(() => {});

      setTimeout(() => {
        bgVideo.style.opacity = 1;
      }, 120);
    }, 400);
  }

  if (bgVideo && bgSource) {
    goToVideo(0);

    bgVideo.addEventListener("ended", () => {
      goToVideo(currentVideo + 1);
    });

    bgVideo.addEventListener("timeupdate", () => {
      if (!heroDots.length || !bgVideo.duration) return;
      const progress = bgVideo.currentTime / bgVideo.duration;
      const activeDot = heroDots[currentVideo];
      if (activeDot) {
        activeDot.style.setProperty("--progress", String(progress));
      }
    });

    heroDots.forEach(dot => {
      dot.addEventListener("click", () => {
        const index = Number(dot.dataset.index);
        if (!Number.isNaN(index) && index !== currentVideo) {
          goToVideo(index);
        }
      });
    });
  }

  // ===== PLAY OVERLAY DO VÍDEO DE TESTIMONIAL =====
  const testimonialVideo = document.querySelector('.testimonial-video-player');
  const playOverlay = document.querySelector('.video-play-overlay');

  if (testimonialVideo && playOverlay) {
    playOverlay.addEventListener('click', () => {
      playOverlay.classList.add('is-hidden');
      testimonialVideo.play().catch(() => {});
    });

    testimonialVideo.addEventListener('play', () => {
      playOverlay.classList.add('is-hidden');
    });

    testimonialVideo.addEventListener('pause', () => {
      if (testimonialVideo.currentTime === 0) {
        playOverlay.classList.remove('is-hidden');
      }
    });
  }

  // ===== WHY TRAIN SLIDER (MOBILE) =====
  const whyGrid = document.querySelector(".why-grid");
  const whyCards = document.querySelectorAll(".why-card");
  const whyDots = document.querySelectorAll(".why-dot");

  if (whyGrid && whyCards.length && whyDots.length) {
    function setActiveWhyDot(index) {
      whyDots.forEach((dot, i) => {
        dot.classList.toggle("is-active", i === index);
      });
    }

    function scrollToWhyCard(index) {
      const card = whyCards[index];
      if (!card) return;

      const offsetLeft = card.offsetLeft;
      whyGrid.scrollTo({
        left: offsetLeft,
        behavior: "smooth"
      });
      setActiveWhyDot(index);
    }

    whyDots.forEach((dot, index) => {
      dot.addEventListener("click", () => {
        scrollToWhyCard(index);
      });
    });

    whyGrid.addEventListener("scroll", () => {
      let closestIndex = 0;
      let minDiff = Infinity;
      const scrollLeft = whyGrid.scrollLeft;

      whyCards.forEach((card, index) => {
        const diff = Math.abs(card.offsetLeft - scrollLeft);
        if (diff < minDiff) {
          minDiff = diff;
          closestIndex = index;
        }
      });

      setActiveWhyDot(closestIndex);
    });
  }

  // ===== FAQ ACCORDION =====
  const faqItems = document.querySelectorAll(".faq-item");
  faqItems.forEach((item) => {
    const btn = item.querySelector(".faq-question");
    if (!btn) return;
    btn.addEventListener("click", () => {
      item.classList.toggle("active");
    });
  });
});
