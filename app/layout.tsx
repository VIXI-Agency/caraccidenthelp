import type { Metadata } from "next";
import { Raleway, Open_Sans } from "next/font/google";
import "./globals.css";

const raleway = Raleway({
  subsets: ["latin"],
  variable: "--font-raleway",
  weight: ["400", "600", "700", "800"],
  display: "swap",
});

const openSans = Open_Sans({
  subsets: ["latin"],
  variable: "--font-open-sans",
  weight: ["400", "500", "600"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Injured in an Accident? | CarAccidentHelp.net",
  description:
    "Connect with top car accident lawyers who fight to maximize your payout. Use our free compensation calculator to see what you qualify for.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className={`${raleway.variable} ${openSans.variable}`}>
      <head>
        {/* Preload LCP hero image so it starts downloading immediately */}
        <link rel="preload" as="image" href="/hero-bg-full.webp" fetchPriority="high" />
        {/* Preconnect to Growform so the form loads faster */}
        <link rel="preconnect" href="https://embed.growform.co" />
        <link rel="preconnect" href="https://assets.growform.co" />
      </head>
      <body className="min-h-screen flex flex-col antialiased">
        {children}
      </body>
    </html>
  );
}
