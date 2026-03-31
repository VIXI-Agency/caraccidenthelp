"use client";
import { useEffect } from "react";
import Image from "next/image";

const GROWFORM_SRC = "https://embed.growform.co/client/67cf74bca2ec54000b491be6";

function loadGrowform(containerId: string) {
  const wrapper = document.getElementById(containerId);
  if (!wrapper || wrapper.querySelector("script")) return;
  const script = document.createElement("script");
  script.type = "text/javascript";
  script.src = GROWFORM_SRC;
  wrapper.appendChild(script);
}

const benefits = [
  {
    img: "/icon-medical.webp",
    title: "Medical Expenses",
    desc: "Covers treatment costs related to accident injuries.",
  },
  {
    img: "/icon-wages.webp",
    title: "Lost Wages",
    desc: "You may be compensated if your injuries affected your ability to work.",
  },
  {
    img: "/icon-vehicle.webp",
    title: "Vehicle Damage",
    desc: "Helps cover repairs or replacement of your vehicle.",
  },
  {
    img: "/icon-pain.webp",
    title: "Pain & Suffering",
    desc: "May include physical or emotional distress caused by the car accident.",
  },
];

export default function Home() {
  useEffect(() => {
    const wrapper = document.getElementById("growform-wrapper");
    if (!wrapper) return;
    // Defer Growform until the form section is near the viewport
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting) {
          loadGrowform("growform-wrapper");
          observer.disconnect();
        }
      },
      { rootMargin: "300px" }
    );
    observer.observe(wrapper);
    return () => observer.disconnect();
  }, []);

  return (
    <>
      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTION 0 — HERO (desktop-only in original)
          Dark bg with hero background image
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section
        style={{
          backgroundImage:
            "linear-gradient(rgba(43,49,60,0.65),rgba(43,49,60,0.65)), url('/hero-bg-full.webp')",
          backgroundSize: "cover",
          backgroundPosition: "center top",
          padding: "60px 24px 70px",
          textAlign: "center",
        }}
      >
        {/* Logo */}
        <div style={{ marginBottom: 24 }}>
          <Image
            src="/logo-full.png"
            alt="Car Accident Help – Maximize Your Auto Accident Payout"
            width={320}
            height={66}
            priority
            style={{ height: 66, width: "auto", display: "inline-block" }}
          />
        </div>

        {/* H1 */}
        <h1
          style={{
            fontFamily: "var(--font-raleway), Arial, sans-serif",
            fontSize: 34,
            fontWeight: 700,
            color: "#ffffff",
            margin: "0 0 20px",
            lineHeight: 1.4,
            textTransform: "none",
          }}
        >
          Injured in an Accident?
        </h1>

        {/* Sub-heading */}
        <p
          style={{
            fontFamily: "var(--font-raleway), Arial, sans-serif",
            fontWeight: 600,
            fontSize: 20,
            color: "#ffffff",
            margin: 0,
            lineHeight: 1.5,
          }}
        >
          Connect With{" "}
          <span style={{ color: "#16bc35" }}>Top Car Accident Lawyers</span>{" "}
          Who Fight to{" "}
          <strong style={{ textDecoration: "underline" }}>
            Maximize Your Payout
          </strong>
        </p>
      </section>

      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTIONS 2+3 — BANNER + GROWFORM + FORM
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section id="form-section" style={{ background: "#ffffff", padding: "32px 24px 0" }}>
        <div
          style={{
            maxWidth: 1140,
            margin: "0 auto",
            padding: "0",
          }}
        >
          {/* Gray rounded card */}
          <div
            style={{
              background: "#e2e2e2",
              borderRadius: "28px 28px 0 0",
              padding: "28px 48px",
              textAlign: "center",
            }}
          >
            <p
              style={{
                fontFamily: "var(--font-raleway), Arial, sans-serif",
                fontWeight: 400,
                fontSize: 35,
                color: "#003954",
                margin: 0,
                lineHeight: 1.5,
              }}
            >
              Use our{" "}
              <strong style={{ fontWeight: 700 }}>Free Compensation Calculator</strong>{" "}
              to See What You Qualify For
            </p>
          </div>
          {/* Growform — between the two CTA texts */}
          <div style={{ margin: 0, padding: 0, lineHeight: 0, fontSize: 0 }}>
            <div id="growform-wrapper" />
          </div>
          {/* Bottom gray cap — closes the form visually */}
          <div
            style={{
              background: "#e2e2e2",
              borderRadius: "0 0 28px 28px",
              padding: "28px 48px",
              marginTop: "-16px",
              marginBottom: 36,
            }}
          />

          {/* 3-col */}
          <div
            style={{
              display: "flex",
              alignItems: "flex-start",
              flexWrap: "wrap",
            }}
          >
            {/* COL 1 — car image (25%) */}
            <div style={{ flex: "0 0 25%", width: "25%", display: "flex", justifyContent: "center" }}>
              <Image
                src="/accident-car.webp"
                alt="Car accident scene"
                width={300}
                height={229}
                style={{ width: "100%", height: "auto", display: "block" }}
              />
            </div>

            {/* COL 2 — empty spacer (10%) */}
            <div style={{ flex: "0 0 10%", width: "10%" }} />

            {/* COL 3 — heading + text + button (65%) */}
            <div style={{ flex: "0 0 65%", width: "65%" }}>
              <h2
                style={{
                  fontFamily: "var(--font-raleway), Arial, sans-serif",
                  fontWeight: 700,
                  fontSize: 29,
                  color: "#3b4251",
                  marginBottom: 16,
                  lineHeight: 1.4,
                  textTransform: "none",
                }}
              >
                Try Our Compensation{" "}
                <span style={{ color: "#16bc35" }}>Calculator</span> And See How
                Much You Could Get For Compensation
              </h2>
              <p
                style={{
                  fontFamily: "var(--font-open-sans), Arial, sans-serif",
                  fontSize: 15,
                  color: "#666666",
                  lineHeight: 2,
                  marginBottom: 24,
                }}
              >
                Our car accident lawyers{" "}
                <em>use</em> this Compensation Calculator to get a clear idea of
                how much our clients could be entitled for compensation for their
                accidents. Try it today for{" "}
                <strong style={{ color: "#3b4251" }}>free</strong>, and get an
                answer within minutes!
              </p>
              <a
                href="#form-section"
                style={{
                  display: "block",
                  background: "#16bc35",
                  color: "#ffffff",
                  fontFamily: "var(--font-raleway), Arial, sans-serif",
                  fontWeight: 700,
                  fontSize: 20,
                  textAlign: "center",
                  textTransform: "uppercase",
                  letterSpacing: "0.05em",
                  padding: "16px 32px",
                  borderRadius: 10,
                  textDecoration: "none",
                }}
              >
                See If You Qualify
              </a>
            </div>
          </div>
        </div>
      </section>

      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTION 5 — BENEFITS HEADING (all screens)
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section style={{ background: "#ffffff", padding: "48px 24px 0" }}>
        <div style={{ maxWidth: 1140, margin: "0 auto", textAlign: "center" }}>
          <h2
            style={{
              fontFamily: "var(--font-raleway), Arial, sans-serif",
              fontWeight: 700,
              fontSize: 24,
              color: "#3b4251",
              margin: 0,
              textTransform: "none",
            }}
          >
            Our Car Accident Lawyers Can Help{" "}
            <span style={{ textDecoration: "underline" }}>You</span> Get
            Compensation For&hellip;
          </h2>
        </div>
      </section>

      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTIONS 7+8 — BENEFITS GRID (4 columns)
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section style={{ background: "#ffffff", padding: "24px 24px 0" }}>
        <div
          style={{
            maxWidth: 1140,
            margin: "0 auto",
            display: "grid",
            gridTemplateColumns: "repeat(2, 1fr)",
            gap: 32,
          }}
        >
          {benefits.map(({ img, title, desc }) => (
            <div
              key={title}
              style={{
                display: "flex",
                alignItems: "flex-start",
                gap: 20,
                padding: "16px 0",
              }}
            >
              <Image
                src={img}
                alt={title}
                width={200}
                height={200}
                style={{
                  width: 140,
                  height: 140,
                  flexShrink: 0,
                }}
              />
              <div style={{ paddingTop: 8 }}>
                <h3
                  style={{
                    fontFamily: "var(--font-raleway), Arial, sans-serif",
                    fontWeight: 700,
                    fontSize: 24,
                    color: "#3b4251",
                    textTransform: "uppercase",
                    marginBottom: 8,
                  }}
                >
                  {title}
                </h3>
                <p
                  style={{
                    fontFamily: "var(--font-open-sans), Arial, sans-serif",
                    fontSize: 14,
                    color: "#666666",
                    lineHeight: 1.7,
                    margin: 0,
                  }}
                >
                  {desc}
                </p>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTION 9 — SEE IF YOU QUALIFY button
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section style={{ background: "#ffffff", padding: "40px 24px 48px" }}>
        <div style={{ maxWidth: 1140, margin: "0 auto", textAlign: "center" }}>
          <a
            href="#form-section"
            style={{
              display: "inline-block",
              background: "#16bc35",
              color: "#ffffff",
              fontFamily: "var(--font-raleway), Arial, sans-serif",
              fontWeight: 700,
              fontSize: 20,
              textTransform: "uppercase",
              letterSpacing: "0.05em",
              padding: "14px 64px",
              borderRadius: 10,
              textDecoration: "none",
            }}
          >
            See If You Qualify
          </a>
        </div>
      </section>

      {/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          SECTION 11 — DON'T WAIT + FOOTER (all screens)
          Has --awb-color5 (green) border-top
      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */}
      <section
        style={{
          background: "#2b313c",
          borderTop: "4px solid #16bc35",
        }}
      >
        {/* DON'T WAIT banner */}
        <div
          style={{
            padding: "32px 24px",
            textAlign: "center",
            borderBottom: "1px solid #3b4251",
          }}
        >
          <p
            style={{
              fontFamily: "var(--font-raleway), Arial, sans-serif",
              fontWeight: 700,
              fontSize: 18,
              color: "#ffffff",
              textTransform: "uppercase",
              letterSpacing: "0.05em",
              margin: 0,
            }}
          >
            Don&apos;t Wait &ndash; Get The Help You Deserve Today. It&apos;s
            Fast and Easy to Start.
          </p>
        </div>

        {/* Legal disclaimer */}
        <div
          style={{
            maxWidth: 1140,
            margin: "0 auto",
            padding: "32px 24px",
          }}
        >
          <p
            style={{
              fontFamily: "var(--font-open-sans), Arial, sans-serif",
              fontSize: 9,
              color: "#888",
              lineHeight: 1.8,
              marginBottom: 24,
            }}
          >
            Advertising paid for by participating attorneys in a joint
            advertising program, including West Coast Trial Lawyers, licensed to
            practice law in California. A complete list of joint advertising
            attorneys can be found at{" "}
            <a
              href="https://www.caraccidenthelp.net/sponsors/"
              style={{ color: "#16bc35" }}
            >
              https://www.caraccidenthelp.net/sponsors/
            </a>
            . You can request an attorney by name. CarAccidenthelp.net is not a
            law firm or an attorney referral service. This advertisement is not
            legal advice and is not a guarantee or prediction of the outcome of
            your legal matter. Every case is different. The outcome depends on
            the laws, facts, and circumstances unique to each case. Hiring an
            attorney is an important decision that should not be based solely on
            advertising. Request free information about your attorney&apos;s
            background and experience. This advertising does not imply a higher
            quality of legal services than that provided by other attorneys. We
            do not provide legal advice or legal representation. No
            attorney-client relationship is formed by contacting us.
          </p>

          {/* Contact columns */}
          <div
            style={{
              display: "flex",
              gap: 48,
              flexWrap: "wrap",
              marginBottom: 24,
              paddingTop: 16,
              borderTop: "1px solid #3b4251",
            }}
          >
            <div>
              <p
                style={{
                  fontFamily: "var(--font-raleway), Arial, sans-serif",
                  fontWeight: 700,
                  color: "#fff",
                  marginBottom: 4,
                  fontSize: 9,
                }}
              >
                Address:
              </p>
              <p style={{ color: "#888", fontSize: 9, margin: 0 }}>
                6119 Greenville #424, Dallas, TX 75206
              </p>
            </div>
            <div>
              <p
                style={{
                  fontFamily: "var(--font-raleway), Arial, sans-serif",
                  fontWeight: 700,
                  color: "#fff",
                  marginBottom: 4,
                  fontSize: 14,
                }}
              >
                Phone:
              </p>
              <a
                href="tel:+12543584941"
                style={{ color: "#16bc35", fontSize: 9, textDecoration: "none" }}
              >
                +1 254-358-4941
              </a>
            </div>
            <div>
              <p
                style={{
                  fontFamily: "var(--font-raleway), Arial, sans-serif",
                  fontWeight: 700,
                  color: "#fff",
                  marginBottom: 4,
                  fontSize: 14,
                }}
              >
                Email:
              </p>
              <a
                href="mailto:support@caraccidenthelp.net"
                style={{ color: "#16bc35", fontSize: 9, textDecoration: "none" }}
              >
                support@caraccidenthelp.net
              </a>
            </div>
          </div>

          {/* Copyright */}
          <div
            style={{
              borderTop: "1px solid #3b4251",
              paddingTop: 20,
              textAlign: "center",
              fontSize: 12,
              color: "#666",
            }}
          >
            <p style={{ margin: "0 0 4px" }}>
              Car Accident Help is a brand owned and operated by Triggerfish
              Leads, LLC.
            </p>
            <p style={{ margin: 0 }}>
              &copy; 2012&ndash;2026 &nbsp;CarAccidentHelp.net &nbsp;&mdash;&nbsp;
              All Rights Reserved &nbsp;&mdash;&nbsp; Get the Compensation You
              Deserve Today &nbsp;&mdash;&nbsp;{" "}
              <a href="https://saddlebrown-nightingale-345621.hostingersite.com/terms/" style={{ color: "#16bc35" }}>
                Terms &amp; Conditions.
              </a>{" "}
              &nbsp;
              <a href="https://saddlebrown-nightingale-345621.hostingersite.com/privacy-policy/" style={{ color: "#16bc35" }}>
                Privacy Policy
              </a>
            </p>
          </div>
        </div>
      </section>
    </>
  );
}
