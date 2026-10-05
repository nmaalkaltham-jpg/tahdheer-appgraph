--
-- PostgreSQL database dump
--

\restrict LxATCc5EABoXyMNY0nhXT2tAKbfsRSLfyvWMuHVwxjEYsq52tz89Jb7TBFSwxcS

-- Dumped from database version 18.6 (Debian 18.6-1.pgdg13+2)
-- Dumped by pg_dump version 18.6 (Debian 18.6-1.pgdg13+2)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_audit_log; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.admin_audit_log (
    id bigint NOT NULL,
    admin_user_id bigint,
    actor_email character varying(255),
    action character varying(255) NOT NULL,
    entity_type character varying(255) NOT NULL,
    entity_id character varying(255),
    before json,
    after json,
    reason character varying(255),
    ip character varying(45),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.admin_audit_log OWNER TO tahdheer;

--
-- Name: admin_audit_log_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.admin_audit_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_audit_log_id_seq OWNER TO tahdheer;

--
-- Name: admin_audit_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.admin_audit_log_id_seq OWNED BY public.admin_audit_log.id;


--
-- Name: admin_notes; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.admin_notes (
    id bigint NOT NULL,
    entity_type character varying(32) NOT NULL,
    entity_id bigint NOT NULL,
    admin_user_id bigint,
    author_email character varying(255) NOT NULL,
    body text NOT NULL,
    edited_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.admin_notes OWNER TO tahdheer;

--
-- Name: admin_notes_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.admin_notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_notes_id_seq OWNER TO tahdheer;

--
-- Name: admin_notes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.admin_notes_id_seq OWNED BY public.admin_notes.id;


--
-- Name: admin_users; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.admin_users (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role character varying(255) DEFAULT 'read_only'::character varying NOT NULL,
    last_login_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT admin_users_role_check CHECK (((role)::text = ANY ((ARRAY['owner'::character varying, 'administrator'::character varying, 'product_manager'::character varying, 'order_manager'::character varying, 'seo_manager'::character varying, 'search_manager'::character varying, 'analytics'::character varying, 'read_only'::character varying])::text[])))
);


ALTER TABLE public.admin_users OWNER TO tahdheer;

--
-- Name: admin_users_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.admin_users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_users_id_seq OWNER TO tahdheer;

--
-- Name: admin_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.admin_users_id_seq OWNED BY public.admin_users.id;


--
-- Name: ai_requests; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.ai_requests (
    id bigint NOT NULL,
    provider character varying(40) NOT NULL,
    model character varying(80),
    task character varying(40) NOT NULL,
    field character varying(40),
    product_id bigint,
    admin_user_id bigint,
    actor_email character varying(190),
    prompt_version character varying(32),
    status character varying(20) NOT NULL,
    error_code character varying(40),
    attempts smallint DEFAULT '1'::smallint NOT NULL,
    input_tokens integer,
    output_tokens integer,
    cost_minor integer,
    latency_ms integer,
    suggestions smallint,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.ai_requests OWNER TO tahdheer;

--
-- Name: ai_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.ai_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ai_requests_id_seq OWNER TO tahdheer;

--
-- Name: ai_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.ai_requests_id_seq OWNED BY public.ai_requests.id;


--
-- Name: analytics_daily; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.analytics_daily (
    id bigint NOT NULL,
    day date NOT NULL,
    event_type character varying(40) NOT NULL,
    events integer DEFAULT 0 NOT NULL,
    visitors integer DEFAULT 0 NOT NULL,
    value_minor bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.analytics_daily OWNER TO tahdheer;

--
-- Name: analytics_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.analytics_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.analytics_daily_id_seq OWNER TO tahdheer;

--
-- Name: analytics_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.analytics_daily_id_seq OWNED BY public.analytics_daily.id;


--
-- Name: analytics_events; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.analytics_events (
    id bigint NOT NULL,
    occurred_at timestamp(0) without time zone NOT NULL,
    event_type character varying(40) NOT NULL,
    visitor_hash character(64),
    session_hash character(64),
    product_id bigint,
    order_id bigint,
    value_minor integer,
    path character varying(200),
    referrer_host character varying(120),
    device character varying(10),
    is_logged_in boolean DEFAULT false NOT NULL,
    meta json,
    dedup_key character varying(120),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.analytics_events OWNER TO tahdheer;

--
-- Name: analytics_events_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.analytics_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.analytics_events_id_seq OWNER TO tahdheer;

--
-- Name: analytics_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.analytics_events_id_seq OWNED BY public.analytics_events.id;


--
-- Name: analytics_product_daily; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.analytics_product_daily (
    id bigint NOT NULL,
    day date NOT NULL,
    product_id bigint NOT NULL,
    views integer DEFAULT 0 NOT NULL,
    add_to_carts integer DEFAULT 0 NOT NULL,
    purchases integer DEFAULT 0 NOT NULL,
    revenue_minor bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.analytics_product_daily OWNER TO tahdheer;

--
-- Name: analytics_product_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.analytics_product_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.analytics_product_daily_id_seq OWNER TO tahdheer;

--
-- Name: analytics_product_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.analytics_product_daily_id_seq OWNED BY public.analytics_product_daily.id;


--
-- Name: asset_extractions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.asset_extractions (
    id bigint NOT NULL,
    asset_id bigint,
    asset_sha256 character varying(64) NOT NULL,
    extractor character varying(40) NOT NULL,
    extractor_version character varying(20) DEFAULT '1'::character varying NOT NULL,
    status character varying(24) DEFAULT 'pending'::character varying NOT NULL,
    text text,
    units integer,
    meta json,
    error_code character varying(40),
    duration_ms integer,
    bytes_read bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.asset_extractions OWNER TO tahdheer;

--
-- Name: asset_extractions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.asset_extractions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.asset_extractions_id_seq OWNER TO tahdheer;

--
-- Name: asset_extractions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.asset_extractions_id_seq OWNED BY public.asset_extractions.id;


--
-- Name: assets; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.assets (
    id bigint NOT NULL,
    provider character varying(255) NOT NULL,
    provider_ref character varying(255) NOT NULL,
    original_name character varying(255) NOT NULL,
    customer_label character varying(255),
    sha256 character varying(64) NOT NULL,
    size_bytes bigint NOT NULL,
    mime character varying(255) NOT NULL,
    visibility character varying(255) DEFAULT 'paid_private'::character varying NOT NULL,
    usage_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT assets_visibility_check CHECK (((visibility)::text = ANY ((ARRAY['public_sample'::character varying, 'paid_private'::character varying])::text[])))
);


ALTER TABLE public.assets OWNER TO tahdheer;

--
-- Name: assets_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.assets_id_seq OWNER TO tahdheer;

--
-- Name: assets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.assets_id_seq OWNED BY public.assets.id;


--
-- Name: auth_identities; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.auth_identities (
    id bigint NOT NULL,
    customer_id bigint NOT NULL,
    provider character varying(255) NOT NULL,
    provider_subject character varying(255) NOT NULL,
    verified_email character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.auth_identities OWNER TO tahdheer;

--
-- Name: auth_identities_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.auth_identities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.auth_identities_id_seq OWNER TO tahdheer;

--
-- Name: auth_identities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.auth_identities_id_seq OWNED BY public.auth_identities.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache OWNER TO tahdheer;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO tahdheer;

--
-- Name: campaigns; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.campaigns (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description character varying(500),
    url character varying(255) NOT NULL,
    starts_at timestamp(0) without time zone,
    ends_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT campaigns_window_ordered CHECK (((starts_at IS NULL) OR (starts_at < ends_at)))
);


ALTER TABLE public.campaigns OWNER TO tahdheer;

--
-- Name: campaigns_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.campaigns_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.campaigns_id_seq OWNER TO tahdheer;

--
-- Name: campaigns_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.campaigns_id_seq OWNED BY public.campaigns.id;


--
-- Name: cart_items; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.cart_items (
    id bigint NOT NULL,
    cart_id bigint NOT NULL,
    product_id bigint NOT NULL,
    qty integer DEFAULT 1 NOT NULL,
    unit_price_snapshot_minor integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.cart_items OWNER TO tahdheer;

--
-- Name: cart_items_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.cart_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.cart_items_id_seq OWNER TO tahdheer;

--
-- Name: cart_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.cart_items_id_seq OWNED BY public.cart_items.id;


--
-- Name: carts; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.carts (
    id bigint NOT NULL,
    customer_id bigint,
    token character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    coupon_id bigint
);


ALTER TABLE public.carts OWNER TO tahdheer;

--
-- Name: carts_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.carts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.carts_id_seq OWNER TO tahdheer;

--
-- Name: carts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.carts_id_seq OWNED BY public.carts.id;


--
-- Name: coupons; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.coupons (
    id bigint NOT NULL,
    code character varying(255) NOT NULL,
    type character varying(255) NOT NULL,
    value integer NOT NULL,
    first_order_per_account boolean DEFAULT false NOT NULL,
    usage_limit integer,
    usage_count integer DEFAULT 0 NOT NULL,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT coupons_type_check CHECK (((type)::text = ANY ((ARRAY['percent'::character varying, 'fixed'::character varying])::text[])))
);


ALTER TABLE public.coupons OWNER TO tahdheer;

--
-- Name: coupons_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.coupons_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.coupons_id_seq OWNER TO tahdheer;

--
-- Name: coupons_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.coupons_id_seq OWNED BY public.coupons.id;


--
-- Name: crawl_runs; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.crawl_runs (
    id bigint NOT NULL,
    status character varying(20) DEFAULT 'running'::character varying NOT NULL,
    pages integer DEFAULT 0 NOT NULL,
    links integer DEFAULT 0 NOT NULL,
    broken integer DEFAULT 0 NOT NULL,
    orphans integer DEFAULT 0 NOT NULL,
    max_depth integer DEFAULT 0 NOT NULL,
    failure text,
    started_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    finished_at timestamp(0) without time zone
);


ALTER TABLE public.crawl_runs OWNER TO tahdheer;

--
-- Name: crawl_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.crawl_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.crawl_runs_id_seq OWNER TO tahdheer;

--
-- Name: crawl_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.crawl_runs_id_seq OWNED BY public.crawl_runs.id;


--
-- Name: crawled_pages; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.crawled_pages (
    id bigint NOT NULL,
    run_id bigint NOT NULL,
    path character varying(500) NOT NULL,
    status smallint,
    depth smallint,
    inbound integer DEFAULT 0 NOT NULL,
    outbound integer DEFAULT 0 NOT NULL,
    title character varying(300),
    canonical character varying(500),
    robots character varying(120),
    redirect_to character varying(500),
    discovered_from character varying(20) DEFAULT 'link'::character varying NOT NULL,
    crawled_at timestamp(0) without time zone
);


ALTER TABLE public.crawled_pages OWNER TO tahdheer;

--
-- Name: crawled_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.crawled_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.crawled_pages_id_seq OWNER TO tahdheer;

--
-- Name: crawled_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.crawled_pages_id_seq OWNED BY public.crawled_pages.id;


--
-- Name: curriculum_aliases; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.curriculum_aliases (
    id bigint NOT NULL,
    entity_type character varying(255) NOT NULL,
    entity_id bigint NOT NULL,
    alias character varying(255) NOT NULL,
    normalized_alias character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.curriculum_aliases OWNER TO tahdheer;

--
-- Name: curriculum_aliases_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.curriculum_aliases_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.curriculum_aliases_id_seq OWNER TO tahdheer;

--
-- Name: curriculum_aliases_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.curriculum_aliases_id_seq OWNED BY public.curriculum_aliases.id;


--
-- Name: customer_sessions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.customer_sessions (
    id bigint NOT NULL,
    customer_id bigint NOT NULL,
    token_hash character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    last_seen_at timestamp(0) without time zone,
    user_agent character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.customer_sessions OWNER TO tahdheer;

--
-- Name: customer_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.customer_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_sessions_id_seq OWNER TO tahdheer;

--
-- Name: customer_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.customer_sessions_id_seq OWNED BY public.customer_sessions.id;


--
-- Name: customers; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.customers (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    phone character varying(255),
    locale character varying(10) DEFAULT 'ar'::character varying NOT NULL,
    marketing_consent boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.customers OWNER TO tahdheer;

--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customers_id_seq OWNER TO tahdheer;

--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.customers_id_seq OWNED BY public.customers.id;


--
-- Name: design_component_versions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.design_component_versions (
    id bigint NOT NULL,
    component_id bigint NOT NULL,
    version integer NOT NULL,
    settings json NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.design_component_versions OWNER TO tahdheer;

--
-- Name: design_component_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.design_component_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.design_component_versions_id_seq OWNER TO tahdheer;

--
-- Name: design_component_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.design_component_versions_id_seq OWNED BY public.design_component_versions.id;


--
-- Name: design_components; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.design_components (
    id bigint NOT NULL,
    component_key character varying(120) NOT NULL,
    component_type character varying(60) NOT NULL,
    variant character varying(60),
    draft_settings json NOT NULL,
    published_settings json,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT design_components_publish_pair CHECK (((published_version IS NULL) = (published_settings IS NULL)))
);


ALTER TABLE public.design_components OWNER TO tahdheer;

--
-- Name: design_components_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.design_components_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.design_components_id_seq OWNER TO tahdheer;

--
-- Name: design_components_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.design_components_id_seq OWNED BY public.design_components.id;


--
-- Name: design_heroes; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.design_heroes (
    id bigint NOT NULL,
    hero_key character varying(120) NOT NULL,
    name character varying(160) NOT NULL,
    target_type character varying(40) NOT NULL,
    target_ref character varying(190),
    structure character varying(60) NOT NULL,
    variant character varying(60),
    draft_settings json NOT NULL,
    published_settings json,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT design_heroes_publish_pair CHECK (((published_version IS NULL) = (published_settings IS NULL)))
);


ALTER TABLE public.design_heroes OWNER TO tahdheer;

--
-- Name: design_heroes_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.design_heroes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.design_heroes_id_seq OWNER TO tahdheer;

--
-- Name: design_heroes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.design_heroes_id_seq OWNED BY public.design_heroes.id;


--
-- Name: download_events; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.download_events (
    id bigint NOT NULL,
    entitlement_id bigint,
    event_type character varying(255) NOT NULL,
    meta json,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.download_events OWNER TO tahdheer;

--
-- Name: download_events_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.download_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.download_events_id_seq OWNER TO tahdheer;

--
-- Name: download_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.download_events_id_seq OWNED BY public.download_events.id;


--
-- Name: download_grants; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.download_grants (
    id bigint NOT NULL,
    entitlement_id bigint NOT NULL,
    token_hash character varying(64) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    used_at timestamp(0) without time zone,
    ip character varying(45),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    asset_id bigint
);


ALTER TABLE public.download_grants OWNER TO tahdheer;

--
-- Name: download_grants_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.download_grants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.download_grants_id_seq OWNER TO tahdheer;

--
-- Name: download_grants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.download_grants_id_seq OWNED BY public.download_grants.id;


--
-- Name: entitlements; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.entitlements (
    id bigint NOT NULL,
    customer_id bigint NOT NULL,
    order_id bigint NOT NULL,
    order_item_id bigint NOT NULL,
    product_id bigint NOT NULL,
    version_label character varying(255),
    granted_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone,
    download_limit integer,
    download_count integer DEFAULT 0 NOT NULL,
    status character varying(255) DEFAULT 'ACTIVE'::character varying NOT NULL,
    revoked_at timestamp(0) without time zone,
    revocation_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT entitlements_status_check CHECK (((status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'EXPIRED'::character varying, 'REVOKED'::character varying, 'SUSPENDED'::character varying])::text[])))
);


ALTER TABLE public.entitlements OWNER TO tahdheer;

--
-- Name: entitlements_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.entitlements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.entitlements_id_seq OWNER TO tahdheer;

--
-- Name: entitlements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.entitlements_id_seq OWNED BY public.entitlements.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO tahdheer;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO tahdheer;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: features; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.features (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    scope character varying(255) NOT NULL,
    value text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.features OWNER TO tahdheer;

--
-- Name: features_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.features_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.features_id_seq OWNER TO tahdheer;

--
-- Name: features_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.features_id_seq OWNED BY public.features.id;


--
-- Name: grades; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.grades (
    id bigint NOT NULL,
    stage_id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.grades OWNER TO tahdheer;

--
-- Name: grades_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.grades_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.grades_id_seq OWNER TO tahdheer;

--
-- Name: grades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.grades_id_seq OWNED BY public.grades.id;


--
-- Name: internal_links; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.internal_links (
    id bigint NOT NULL,
    run_id bigint NOT NULL,
    from_path character varying(500) NOT NULL,
    to_path character varying(500) NOT NULL,
    anchor character varying(300),
    placement character varying(20) DEFAULT 'body'::character varying NOT NULL,
    nofollow boolean DEFAULT false NOT NULL
);


ALTER TABLE public.internal_links OWNER TO tahdheer;

--
-- Name: internal_links_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.internal_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.internal_links_id_seq OWNER TO tahdheer;

--
-- Name: internal_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.internal_links_id_seq OWNED BY public.internal_links.id;


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.invoices (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    number character varying(255) NOT NULL,
    issued_at timestamp(0) without time zone NOT NULL,
    snapshot json NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.invoices OWNER TO tahdheer;

--
-- Name: invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoices_id_seq OWNER TO tahdheer;

--
-- Name: invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.invoices_id_seq OWNED BY public.invoices.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO tahdheer;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO tahdheer;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO tahdheer;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: kinds; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.kinds (
    id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.kinds OWNER TO tahdheer;

--
-- Name: kinds_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.kinds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kinds_id_seq OWNER TO tahdheer;

--
-- Name: kinds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.kinds_id_seq OWNED BY public.kinds.id;


--
-- Name: locales; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.locales (
    code character varying(12) NOT NULL,
    name_ar character varying(255) NOT NULL,
    name_en character varying(255) NOT NULL,
    hreflang character varying(20) NOT NULL,
    direction character varying(255) DEFAULT 'rtl'::character varying NOT NULL,
    url_prefix character varying(12),
    is_default boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    is_routable boolean DEFAULT false NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT locales_direction_check CHECK (((direction)::text = ANY ((ARRAY['rtl'::character varying, 'ltr'::character varying])::text[])))
);


ALTER TABLE public.locales OWNER TO tahdheer;

--
-- Name: magic_links; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.magic_links (
    id bigint NOT NULL,
    token_hash character varying(64) NOT NULL,
    email character varying(255) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    used_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.magic_links OWNER TO tahdheer;

--
-- Name: magic_links_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.magic_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.magic_links_id_seq OWNER TO tahdheer;

--
-- Name: magic_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.magic_links_id_seq OWNED BY public.magic_links.id;


--
-- Name: material_requests; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.material_requests (
    id bigint NOT NULL,
    stage_id bigint,
    grade_id bigint,
    subject_id bigint,
    semester_id bigint,
    kind character varying(120),
    note text,
    contact character varying(190),
    contact_kind character varying(10),
    source character varying(20) DEFAULT 'form'::character varying NOT NULL,
    query character varying(200),
    status character varying(12) DEFAULT 'new'::character varying NOT NULL,
    decision_note text,
    fingerprint character varying(64) NOT NULL,
    hits integer DEFAULT 1 NOT NULL,
    first_seen_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    last_seen_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.material_requests OWNER TO tahdheer;

--
-- Name: material_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.material_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.material_requests_id_seq OWNER TO tahdheer;

--
-- Name: material_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.material_requests_id_seq OWNED BY public.material_requests.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO tahdheer;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO tahdheer;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: navigation_menus; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.navigation_menus (
    id bigint NOT NULL,
    location character varying(40) NOT NULL,
    name character varying(160) NOT NULL,
    draft_nodes json NOT NULL,
    published_nodes json,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT navigation_menus_publish_pair CHECK (((published_version IS NULL) = (published_nodes IS NULL)))
);


ALTER TABLE public.navigation_menus OWNER TO tahdheer;

--
-- Name: navigation_menus_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.navigation_menus_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.navigation_menus_id_seq OWNER TO tahdheer;

--
-- Name: navigation_menus_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.navigation_menus_id_seq OWNED BY public.navigation_menus.id;


--
-- Name: navigation_publish_operations; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.navigation_publish_operations (
    id uuid NOT NULL,
    idempotency_key character varying(100) NOT NULL,
    menu_id bigint NOT NULL,
    actor_id bigint,
    base_revision integer,
    target_revision integer NOT NULL,
    snapshot_revision integer,
    status character varying(40) NOT NULL,
    current_step character varying(40) NOT NULL,
    failure_code character varying(60),
    failure_detail text,
    verified_fingerprint character(64),
    verified_at timestamp(0) without time zone,
    started_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at timestamp(0) without time zone,
    steps json
);


ALTER TABLE public.navigation_publish_operations OWNER TO tahdheer;

--
-- Name: navigation_revisions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.navigation_revisions (
    id bigint NOT NULL,
    menu_id bigint NOT NULL,
    revision integer NOT NULL,
    nodes json NOT NULL,
    fingerprint character(64) NOT NULL,
    node_count integer DEFAULT 0 NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.navigation_revisions OWNER TO tahdheer;

--
-- Name: navigation_revisions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.navigation_revisions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.navigation_revisions_id_seq OWNER TO tahdheer;

--
-- Name: navigation_revisions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.navigation_revisions_id_seq OWNED BY public.navigation_revisions.id;


--
-- Name: not_found_hits; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.not_found_hits (
    id bigint NOT NULL,
    path character varying(255) NOT NULL,
    referrer character varying(255),
    hits integer DEFAULT 1 NOT NULL,
    last_seen_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    url_hash character varying(64),
    first_seen_at timestamp(0) without time zone
);


ALTER TABLE public.not_found_hits OWNER TO tahdheer;

--
-- Name: not_found_hits_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.not_found_hits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.not_found_hits_id_seq OWNER TO tahdheer;

--
-- Name: not_found_hits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.not_found_hits_id_seq OWNED BY public.not_found_hits.id;


--
-- Name: order_items; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.order_items (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    product_id bigint NOT NULL,
    title_snapshot character varying(255) NOT NULL,
    price_snapshot_minor integer NOT NULL,
    version_label character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.order_items OWNER TO tahdheer;

--
-- Name: order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.order_items_id_seq OWNER TO tahdheer;

--
-- Name: order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.order_items_id_seq OWNED BY public.order_items.id;


--
-- Name: orders; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.orders (
    id bigint NOT NULL,
    number character varying(255) NOT NULL,
    customer_id bigint,
    email character varying(255) NOT NULL,
    phone character varying(255),
    status character varying(255) DEFAULT 'PENDING'::character varying NOT NULL,
    totals_gross_minor integer DEFAULT 0 NOT NULL,
    discount_minor integer DEFAULT 0 NOT NULL,
    coupon_minor integer DEFAULT 0 NOT NULL,
    total_minor integer DEFAULT 0 NOT NULL,
    vat_minor integer DEFAULT 0 NOT NULL,
    order_key_hash character varying(64),
    tax_org_name character varying(255),
    tax_vat_number character varying(15),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    paid_at timestamp(0) without time zone,
    marketing_opt_in boolean DEFAULT false NOT NULL,
    policy_consent_at timestamp(0) without time zone,
    confirmation_sent_at timestamp(0) without time zone,
    payment_failed_mail_charge_id character varying(255),
    payment_failed_mail_at timestamp(0) without time zone,
    reminder_stage smallint DEFAULT '0'::smallint NOT NULL,
    reminded_at timestamp(0) without time zone,
    confirmation_resent_at timestamp(0) without time zone,
    CONSTRAINT orders_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'AWAITING_PAYMENT'::character varying, 'PAID'::character varying, 'PROCESSING'::character varying, 'COMPLETED'::character varying, 'FAILED'::character varying, 'CANCELLED'::character varying, 'REFUNDED'::character varying, 'PARTIALLY_REFUNDED'::character varying])::text[])))
);


ALTER TABLE public.orders OWNER TO tahdheer;

--
-- Name: orders_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.orders_id_seq OWNER TO tahdheer;

--
-- Name: orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.orders_id_seq OWNED BY public.orders.id;


--
-- Name: outbox_events; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.outbox_events (
    id uuid NOT NULL,
    aggregate_type character varying(255) NOT NULL,
    aggregate_id character varying(255) NOT NULL,
    event_type character varying(255) NOT NULL,
    payload json NOT NULL,
    correlation_id character varying(255),
    idempotency_key character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'PENDING'::character varying NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    next_retry_at timestamp(0) without time zone,
    processed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT outbox_events_status_check CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'PROCESSING'::character varying, 'PROCESSED'::character varying, 'FAILED'::character varying, 'DEAD'::character varying])::text[])))
);


ALTER TABLE public.outbox_events OWNER TO tahdheer;

--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO tahdheer;

--
-- Name: payment_events; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.payment_events (
    id bigint NOT NULL,
    payment_id bigint NOT NULL,
    event_type character varying(255) NOT NULL,
    payload json,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.payment_events OWNER TO tahdheer;

--
-- Name: payment_events_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.payment_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payment_events_id_seq OWNER TO tahdheer;

--
-- Name: payment_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.payment_events_id_seq OWNED BY public.payment_events.id;


--
-- Name: payments; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.payments (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    provider character varying(255) NOT NULL,
    intent_id character varying(255),
    method character varying(255),
    masked_instrument character varying(255),
    state character varying(255) DEFAULT 'CREATED'::character varying NOT NULL,
    idempotency_key character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.payments OWNER TO tahdheer;

--
-- Name: payments_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.payments_id_seq OWNER TO tahdheer;

--
-- Name: payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.payments_id_seq OWNED BY public.payments.id;


--
-- Name: product_authors; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_authors (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255),
    bio text,
    profile_url character varying(255),
    consented boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_authors OWNER TO tahdheer;

--
-- Name: product_authors_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_authors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_authors_id_seq OWNER TO tahdheer;

--
-- Name: product_authors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_authors_id_seq OWNED BY public.product_authors.id;


--
-- Name: product_fact_evidence; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_fact_evidence (
    id bigint NOT NULL,
    fact_id bigint NOT NULL,
    asset_id bigint,
    asset_sha256 character varying(64),
    source_provider character varying(24),
    locator character varying(80),
    extractor character varying(40),
    snippet character varying(300),
    confidence numeric(3,2) DEFAULT '0'::numeric NOT NULL,
    contradicts boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_fact_evidence OWNER TO tahdheer;

--
-- Name: product_fact_evidence_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_fact_evidence_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_fact_evidence_id_seq OWNER TO tahdheer;

--
-- Name: product_fact_evidence_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_fact_evidence_id_seq OWNED BY public.product_fact_evidence.id;


--
-- Name: product_facts; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_facts (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    int_value integer,
    text_value character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT product_facts_int_value_nonnegative CHECK (((int_value IS NULL) OR (int_value >= 0)))
);


ALTER TABLE public.product_facts OWNER TO tahdheer;

--
-- Name: product_facts_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_facts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_facts_id_seq OWNER TO tahdheer;

--
-- Name: product_facts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_facts_id_seq OWNED BY public.product_facts.id;


--
-- Name: product_faqs; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_faqs (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    question character varying(500) NOT NULL,
    answer text NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_faqs OWNER TO tahdheer;

--
-- Name: product_faqs_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_faqs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_faqs_id_seq OWNER TO tahdheer;

--
-- Name: product_faqs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_faqs_id_seq OWNED BY public.product_faqs.id;


--
-- Name: product_files; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_files (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    asset_id bigint NOT NULL,
    role character varying(255) DEFAULT 'main'::character varying NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_files OWNER TO tahdheer;

--
-- Name: product_files_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_files_id_seq OWNER TO tahdheer;

--
-- Name: product_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_files_id_seq OWNED BY public.product_files.id;


--
-- Name: product_knowledge_facts; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_knowledge_facts (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    key character varying(60) NOT NULL,
    value_text text,
    value_int integer,
    confidence numeric(3,2) DEFAULT '0'::numeric NOT NULL,
    verification_state character varying(20) DEFAULT 'suggested'::character varying NOT NULL,
    extraction_method character varying(40),
    model character varying(60),
    pipeline_version character varying(20) DEFAULT '1'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_knowledge_facts OWNER TO tahdheer;

--
-- Name: product_knowledge_facts_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_knowledge_facts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_knowledge_facts_id_seq OWNER TO tahdheer;

--
-- Name: product_knowledge_facts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_knowledge_facts_id_seq OWNED BY public.product_knowledge_facts.id;


--
-- Name: product_layouts; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_layouts (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    draft_layout json NOT NULL,
    published_layout json,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_layouts OWNER TO tahdheer;

--
-- Name: product_layouts_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_layouts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_layouts_id_seq OWNER TO tahdheer;

--
-- Name: product_layouts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_layouts_id_seq OWNED BY public.product_layouts.id;


--
-- Name: product_media; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_media (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    url character varying(255) NOT NULL,
    alt_ar character varying(255),
    width integer,
    height integer,
    "position" integer DEFAULT 0 NOT NULL,
    is_cover boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_media OWNER TO tahdheer;

--
-- Name: product_media_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_media_id_seq OWNER TO tahdheer;

--
-- Name: product_media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_media_id_seq OWNED BY public.product_media.id;


--
-- Name: product_prices; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_prices (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    regular_minor integer NOT NULL,
    sale_minor integer,
    sale_from timestamp(0) without time zone,
    sale_to timestamp(0) without time zone,
    currency character(3) DEFAULT 'SAR'::bpchar NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT product_prices_sale_below_regular CHECK (((sale_minor IS NULL) OR (sale_minor < regular_minor)))
);


ALTER TABLE public.product_prices OWNER TO tahdheer;

--
-- Name: product_prices_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_prices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_prices_id_seq OWNER TO tahdheer;

--
-- Name: product_prices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_prices_id_seq OWNED BY public.product_prices.id;


--
-- Name: product_seo; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_seo (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    seo_title character varying(255),
    seo_desc character varying(500),
    quick_answer text,
    synonyms text,
    canonical_override character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.product_seo OWNER TO tahdheer;

--
-- Name: product_seo_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_seo_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_seo_id_seq OWNER TO tahdheer;

--
-- Name: product_seo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_seo_id_seq OWNED BY public.product_seo.id;


--
-- Name: product_translations; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.product_translations (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    locale_code character varying(12) NOT NULL,
    title character varying(255),
    subtitle character varying(255),
    short_desc character varying(1000),
    description text,
    slug character varying(255),
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    is_indexable boolean DEFAULT true NOT NULL,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT product_translations_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying])::text[])))
);


ALTER TABLE public.product_translations OWNER TO tahdheer;

--
-- Name: product_translations_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.product_translations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.product_translations_id_seq OWNER TO tahdheer;

--
-- Name: product_translations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.product_translations_id_seq OWNED BY public.product_translations.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.products (
    id bigint NOT NULL,
    sku character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    subtitle character varying(255),
    short_desc character varying(1000),
    description text,
    resource_type character varying(255),
    format character varying(255),
    stage_id bigint,
    grade_id bigint,
    subject_id bigint,
    semester_id bigint,
    editable character varying(255) DEFAULT 'unset'::character varying NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    replacement_product_id bigint,
    fulfillment_type character varying(255) DEFAULT 'digital'::character varying NOT NULL,
    published_at timestamp(0) without time zone,
    content_hash character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    featured boolean DEFAULT false NOT NULL,
    CONSTRAINT products_editable_check CHECK (((editable)::text = ANY ((ARRAY['yes'::character varying, 'no'::character varying, 'unset'::character varying])::text[]))),
    CONSTRAINT products_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'published'::character varying, 'retired'::character varying])::text[])))
);


ALTER TABLE public.products OWNER TO tahdheer;

--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.products_id_seq OWNER TO tahdheer;

--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: quote_requests; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.quote_requests (
    id bigint NOT NULL,
    org_name character varying(160) NOT NULL,
    contact_name character varying(120) NOT NULL,
    email character varying(190) NOT NULL,
    phone character varying(20),
    vat_number character varying(15),
    product_id bigint NOT NULL,
    seats integer NOT NULL,
    quote json NOT NULL,
    note text,
    notified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    handled_at timestamp(0) without time zone,
    handled_by bigint
);


ALTER TABLE public.quote_requests OWNER TO tahdheer;

--
-- Name: quote_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.quote_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.quote_requests_id_seq OWNER TO tahdheer;

--
-- Name: quote_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.quote_requests_id_seq OWNED BY public.quote_requests.id;


--
-- Name: redirect_rules; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.redirect_rules (
    id bigint NOT NULL,
    from_path character varying(255) NOT NULL,
    to_path character varying(255) NOT NULL,
    status_code smallint DEFAULT '301'::smallint NOT NULL,
    hits integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    enabled boolean DEFAULT true NOT NULL,
    reason character varying(255)
);


ALTER TABLE public.redirect_rules OWNER TO tahdheer;

--
-- Name: redirect_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.redirect_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.redirect_rules_id_seq OWNER TO tahdheer;

--
-- Name: redirect_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.redirect_rules_id_seq OWNED BY public.redirect_rules.id;


--
-- Name: refunds; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.refunds (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    amount_minor integer NOT NULL,
    reason character varying(255),
    revocation_policy character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    notified_at timestamp(0) without time zone
);


ALTER TABLE public.refunds OWNER TO tahdheer;

--
-- Name: refunds_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.refunds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.refunds_id_seq OWNER TO tahdheer;

--
-- Name: refunds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.refunds_id_seq OWNED BY public.refunds.id;


--
-- Name: review_aggregates; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.review_aggregates (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    avg_rating numeric(3,2) NOT NULL,
    count integer NOT NULL,
    distribution json NOT NULL,
    recommend_pct smallint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.review_aggregates OWNER TO tahdheer;

--
-- Name: review_aggregates_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.review_aggregates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.review_aggregates_id_seq OWNER TO tahdheer;

--
-- Name: review_aggregates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.review_aggregates_id_seq OWNED BY public.review_aggregates.id;


--
-- Name: review_category_scores; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.review_category_scores (
    id bigint NOT NULL,
    review_id bigint NOT NULL,
    criterion character varying(32) NOT NULL,
    score smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT review_category_scores_criterion CHECK (((criterion)::text = ANY ((ARRAY['content_quality'::character varying, 'edit_ease'::character varying, 'design_quality'::character varying, 'worksheets_value'::character varying, 'description_match'::character varying, 'download_ease'::character varying])::text[]))),
    CONSTRAINT review_category_scores_range CHECK (((score >= 1) AND (score <= 5)))
);


ALTER TABLE public.review_category_scores OWNER TO tahdheer;

--
-- Name: review_category_scores_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.review_category_scores_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.review_category_scores_id_seq OWNER TO tahdheer;

--
-- Name: review_category_scores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.review_category_scores_id_seq OWNED BY public.review_category_scores.id;


--
-- Name: review_media; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.review_media (
    id bigint NOT NULL,
    review_id bigint NOT NULL,
    asset_id bigint NOT NULL,
    alt character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT review_media_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


ALTER TABLE public.review_media OWNER TO tahdheer;

--
-- Name: review_media_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.review_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.review_media_id_seq OWNER TO tahdheer;

--
-- Name: review_media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.review_media_id_seq OWNED BY public.review_media.id;


--
-- Name: review_replies; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.review_replies (
    id bigint NOT NULL,
    review_id bigint NOT NULL,
    body text NOT NULL,
    author_label character varying(255) NOT NULL,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.review_replies OWNER TO tahdheer;

--
-- Name: review_replies_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.review_replies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.review_replies_id_seq OWNER TO tahdheer;

--
-- Name: review_replies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.review_replies_id_seq OWNED BY public.review_replies.id;


--
-- Name: review_votes; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.review_votes (
    id bigint NOT NULL,
    review_id bigint NOT NULL,
    customer_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.review_votes OWNER TO tahdheer;

--
-- Name: review_votes_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.review_votes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.review_votes_id_seq OWNER TO tahdheer;

--
-- Name: review_votes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.review_votes_id_seq OWNED BY public.review_votes.id;


--
-- Name: reviews; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.reviews (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    customer_id bigint NOT NULL,
    order_id bigint,
    rating smallint NOT NULL,
    title character varying(255),
    body text,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_purchased character varying(255),
    helpful_count integer DEFAULT 0 NOT NULL,
    moderated_at timestamp(0) without time zone,
    moderation_note character varying(255),
    CONSTRAINT reviews_rating_range CHECK (((rating >= 1) AND (rating <= 5))),
    CONSTRAINT reviews_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


ALTER TABLE public.reviews OWNER TO tahdheer;

--
-- Name: reviews_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.reviews_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.reviews_id_seq OWNER TO tahdheer;

--
-- Name: reviews_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.reviews_id_seq OWNED BY public.reviews.id;


--
-- Name: search_index_state; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.search_index_state (
    id bigint NOT NULL,
    engine character varying(255) DEFAULT 'meilisearch'::character varying NOT NULL,
    index_name character varying(255) NOT NULL,
    generation integer DEFAULT 0 NOT NULL,
    last_built_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'idle'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.search_index_state OWNER TO tahdheer;

--
-- Name: search_index_state_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.search_index_state_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.search_index_state_id_seq OWNER TO tahdheer;

--
-- Name: search_index_state_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.search_index_state_id_seq OWNED BY public.search_index_state.id;


--
-- Name: search_queries; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.search_queries (
    id bigint NOT NULL,
    raw_query character varying(500) NOT NULL,
    normalized_query character varying(500) NOT NULL,
    results_count integer DEFAULT 0 NOT NULL,
    zero_result boolean DEFAULT false NOT NULL,
    clicked_product_id bigint,
    "position" integer,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    session_hash character(64),
    provider character varying(32) DEFAULT 'unknown'::character varying NOT NULL,
    degraded boolean DEFAULT false NOT NULL,
    degraded_reason character varying(64),
    latency_ms integer
);


ALTER TABLE public.search_queries OWNER TO tahdheer;

--
-- Name: search_queries_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.search_queries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.search_queries_id_seq OWNER TO tahdheer;

--
-- Name: search_queries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.search_queries_id_seq OWNED BY public.search_queries.id;


--
-- Name: search_synonyms; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.search_synonyms (
    id bigint NOT NULL,
    term character varying(255) NOT NULL,
    synonym character varying(255) NOT NULL,
    direction character varying(255) DEFAULT 'one_way'::character varying NOT NULL,
    scope character varying(255),
    state character varying(255) DEFAULT 'active'::character varying NOT NULL,
    source character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    evidence_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT search_synonyms_direction_check CHECK (((direction)::text = ANY ((ARRAY['one_way'::character varying, 'two_way'::character varying])::text[]))),
    CONSTRAINT search_synonyms_source_check CHECK (((source)::text = ANY ((ARRAY['manual'::character varying, 'learned'::character varying, 'seed'::character varying])::text[]))),
    CONSTRAINT search_synonyms_state_check CHECK (((state)::text = ANY ((ARRAY['active'::character varying, 'pending'::character varying, 'negative'::character varying])::text[])))
);


ALTER TABLE public.search_synonyms OWNER TO tahdheer;

--
-- Name: search_synonyms_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.search_synonyms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.search_synonyms_id_seq OWNER TO tahdheer;

--
-- Name: search_synonyms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.search_synonyms_id_seq OWNED BY public.search_synonyms.id;


--
-- Name: semesters; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.semesters (
    id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.semesters OWNER TO tahdheer;

--
-- Name: semesters_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.semesters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.semesters_id_seq OWNER TO tahdheer;

--
-- Name: semesters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.semesters_id_seq OWNED BY public.semesters.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO tahdheer;

--
-- Name: settings; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.settings OWNER TO tahdheer;

--
-- Name: settings_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settings_id_seq OWNER TO tahdheer;

--
-- Name: settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.settings_id_seq OWNED BY public.settings.id;


--
-- Name: sitemap_url_states; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.sitemap_url_states (
    id bigint NOT NULL,
    provider character varying(64) NOT NULL,
    loc character varying(255) NOT NULL,
    content_hash character varying(64) NOT NULL,
    lastmod_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.sitemap_url_states OWNER TO tahdheer;

--
-- Name: sitemap_url_states_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.sitemap_url_states_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sitemap_url_states_id_seq OWNER TO tahdheer;

--
-- Name: sitemap_url_states_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.sitemap_url_states_id_seq OWNED BY public.sitemap_url_states.id;


--
-- Name: stages; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.stages (
    id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.stages OWNER TO tahdheer;

--
-- Name: stages_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.stages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.stages_id_seq OWNER TO tahdheer;

--
-- Name: stages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.stages_id_seq OWNED BY public.stages.id;


--
-- Name: studio_entry_prefs; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_entry_prefs (
    id bigint NOT NULL,
    admin_user_id bigint NOT NULL,
    registry_id character varying(64) NOT NULL,
    favorite boolean DEFAULT false NOT NULL,
    last_opened_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.studio_entry_prefs OWNER TO tahdheer;

--
-- Name: studio_entry_prefs_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_entry_prefs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_entry_prefs_id_seq OWNER TO tahdheer;

--
-- Name: studio_entry_prefs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_entry_prefs_id_seq OWNED BY public.studio_entry_prefs.id;


--
-- Name: studio_media; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_media (
    id bigint NOT NULL,
    name character varying(160) NOT NULL,
    url character varying(255) NOT NULL,
    alt_ar character varying(200),
    width integer,
    height integer,
    mime character varying(40) NOT NULL,
    bytes bigint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.studio_media OWNER TO tahdheer;

--
-- Name: studio_media_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_media_id_seq OWNER TO tahdheer;

--
-- Name: studio_media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_media_id_seq OWNED BY public.studio_media.id;


--
-- Name: studio_page_versions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_page_versions (
    id bigint NOT NULL,
    page_id bigint NOT NULL,
    version integer NOT NULL,
    kind character varying(20) NOT NULL,
    label character varying(160),
    document json NOT NULL,
    schema_version smallint DEFAULT '1'::smallint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT studio_page_versions_kind CHECK (((kind)::text = ANY ((ARRAY['autosave'::character varying, 'checkpoint'::character varying, 'publish'::character varying])::text[])))
);


ALTER TABLE public.studio_page_versions OWNER TO tahdheer;

--
-- Name: studio_page_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_page_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_page_versions_id_seq OWNER TO tahdheer;

--
-- Name: studio_page_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_page_versions_id_seq OWNED BY public.studio_page_versions.id;


--
-- Name: studio_pages; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_pages (
    id bigint NOT NULL,
    page_key character varying(120) NOT NULL,
    locale character varying(10) DEFAULT 'ar'::character varying NOT NULL,
    name character varying(160) NOT NULL,
    draft_document json NOT NULL,
    published_document json,
    schema_version smallint DEFAULT '1'::smallint NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT studio_pages_publish_pair CHECK (((published_version IS NULL) = (published_document IS NULL)))
);


ALTER TABLE public.studio_pages OWNER TO tahdheer;

--
-- Name: studio_pages_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_pages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_pages_id_seq OWNER TO tahdheer;

--
-- Name: studio_pages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_pages_id_seq OWNED BY public.studio_pages.id;


--
-- Name: studio_symbol_versions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_symbol_versions (
    id bigint NOT NULL,
    symbol_id bigint NOT NULL,
    version integer NOT NULL,
    kind character varying(20) NOT NULL,
    label character varying(160),
    document json NOT NULL,
    schema_version smallint DEFAULT '3'::smallint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT studio_symbol_versions_kind CHECK (((kind)::text = ANY ((ARRAY['autosave'::character varying, 'checkpoint'::character varying, 'publish'::character varying])::text[])))
);


ALTER TABLE public.studio_symbol_versions OWNER TO tahdheer;

--
-- Name: studio_symbol_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_symbol_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_symbol_versions_id_seq OWNER TO tahdheer;

--
-- Name: studio_symbol_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_symbol_versions_id_seq OWNED BY public.studio_symbol_versions.id;


--
-- Name: studio_symbols; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_symbols (
    id bigint NOT NULL,
    name character varying(160) NOT NULL,
    draft_document json NOT NULL,
    published_document json,
    schema_version smallint DEFAULT '3'::smallint NOT NULL,
    version integer DEFAULT 1 NOT NULL,
    published_version integer,
    updated_by bigint,
    published_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT studio_symbols_publish_pair CHECK (((published_version IS NULL) = (published_document IS NULL)))
);


ALTER TABLE public.studio_symbols OWNER TO tahdheer;

--
-- Name: studio_symbols_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_symbols_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_symbols_id_seq OWNER TO tahdheer;

--
-- Name: studio_symbols_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_symbols_id_seq OWNED BY public.studio_symbols.id;


--
-- Name: studio_templates; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.studio_templates (
    id bigint NOT NULL,
    name character varying(160) NOT NULL,
    document json NOT NULL,
    schema_version smallint DEFAULT '1'::smallint NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    source character varying(8) DEFAULT 'page'::character varying NOT NULL
);


ALTER TABLE public.studio_templates OWNER TO tahdheer;

--
-- Name: studio_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.studio_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.studio_templates_id_seq OWNER TO tahdheer;

--
-- Name: studio_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.studio_templates_id_seq OWNED BY public.studio_templates.id;


--
-- Name: subjects; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.subjects (
    id bigint NOT NULL,
    slug character varying(255) NOT NULL,
    name_ar character varying(255) NOT NULL,
    icon character varying(255),
    "position" integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.subjects OWNER TO tahdheer;

--
-- Name: subjects_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.subjects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.subjects_id_seq OWNER TO tahdheer;

--
-- Name: subjects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.subjects_id_seq OWNED BY public.subjects.id;


--
-- Name: theme_versions; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.theme_versions (
    id bigint NOT NULL,
    document json NOT NULL,
    etag character varying(16) NOT NULL,
    kind character varying(20) NOT NULL,
    label character varying(160),
    created_by bigint,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.theme_versions OWNER TO tahdheer;

--
-- Name: theme_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.theme_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.theme_versions_id_seq OWNER TO tahdheer;

--
-- Name: theme_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.theme_versions_id_seq OWNED BY public.theme_versions.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: tahdheer
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO tahdheer;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: tahdheer
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO tahdheer;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: tahdheer
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: admin_audit_log id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_audit_log ALTER COLUMN id SET DEFAULT nextval('public.admin_audit_log_id_seq'::regclass);


--
-- Name: admin_notes id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_notes ALTER COLUMN id SET DEFAULT nextval('public.admin_notes_id_seq'::regclass);


--
-- Name: admin_users id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_users ALTER COLUMN id SET DEFAULT nextval('public.admin_users_id_seq'::regclass);


--
-- Name: ai_requests id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.ai_requests ALTER COLUMN id SET DEFAULT nextval('public.ai_requests_id_seq'::regclass);


--
-- Name: analytics_daily id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_daily ALTER COLUMN id SET DEFAULT nextval('public.analytics_daily_id_seq'::regclass);


--
-- Name: analytics_events id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_events ALTER COLUMN id SET DEFAULT nextval('public.analytics_events_id_seq'::regclass);


--
-- Name: analytics_product_daily id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_product_daily ALTER COLUMN id SET DEFAULT nextval('public.analytics_product_daily_id_seq'::regclass);


--
-- Name: asset_extractions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.asset_extractions ALTER COLUMN id SET DEFAULT nextval('public.asset_extractions_id_seq'::regclass);


--
-- Name: assets id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.assets ALTER COLUMN id SET DEFAULT nextval('public.assets_id_seq'::regclass);


--
-- Name: auth_identities id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.auth_identities ALTER COLUMN id SET DEFAULT nextval('public.auth_identities_id_seq'::regclass);


--
-- Name: campaigns id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.campaigns ALTER COLUMN id SET DEFAULT nextval('public.campaigns_id_seq'::regclass);


--
-- Name: cart_items id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cart_items ALTER COLUMN id SET DEFAULT nextval('public.cart_items_id_seq'::regclass);


--
-- Name: carts id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.carts ALTER COLUMN id SET DEFAULT nextval('public.carts_id_seq'::regclass);


--
-- Name: coupons id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.coupons ALTER COLUMN id SET DEFAULT nextval('public.coupons_id_seq'::regclass);


--
-- Name: crawl_runs id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawl_runs ALTER COLUMN id SET DEFAULT nextval('public.crawl_runs_id_seq'::regclass);


--
-- Name: crawled_pages id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawled_pages ALTER COLUMN id SET DEFAULT nextval('public.crawled_pages_id_seq'::regclass);


--
-- Name: curriculum_aliases id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.curriculum_aliases ALTER COLUMN id SET DEFAULT nextval('public.curriculum_aliases_id_seq'::regclass);


--
-- Name: customer_sessions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customer_sessions ALTER COLUMN id SET DEFAULT nextval('public.customer_sessions_id_seq'::regclass);


--
-- Name: customers id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customers ALTER COLUMN id SET DEFAULT nextval('public.customers_id_seq'::regclass);


--
-- Name: design_component_versions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_component_versions ALTER COLUMN id SET DEFAULT nextval('public.design_component_versions_id_seq'::regclass);


--
-- Name: design_components id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_components ALTER COLUMN id SET DEFAULT nextval('public.design_components_id_seq'::regclass);


--
-- Name: design_heroes id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_heroes ALTER COLUMN id SET DEFAULT nextval('public.design_heroes_id_seq'::regclass);


--
-- Name: download_events id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_events ALTER COLUMN id SET DEFAULT nextval('public.download_events_id_seq'::regclass);


--
-- Name: download_grants id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_grants ALTER COLUMN id SET DEFAULT nextval('public.download_grants_id_seq'::regclass);


--
-- Name: entitlements id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements ALTER COLUMN id SET DEFAULT nextval('public.entitlements_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: features id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.features ALTER COLUMN id SET DEFAULT nextval('public.features_id_seq'::regclass);


--
-- Name: grades id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.grades ALTER COLUMN id SET DEFAULT nextval('public.grades_id_seq'::regclass);


--
-- Name: internal_links id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.internal_links ALTER COLUMN id SET DEFAULT nextval('public.internal_links_id_seq'::regclass);


--
-- Name: invoices id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.invoices ALTER COLUMN id SET DEFAULT nextval('public.invoices_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: kinds id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.kinds ALTER COLUMN id SET DEFAULT nextval('public.kinds_id_seq'::regclass);


--
-- Name: magic_links id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.magic_links ALTER COLUMN id SET DEFAULT nextval('public.magic_links_id_seq'::regclass);


--
-- Name: material_requests id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests ALTER COLUMN id SET DEFAULT nextval('public.material_requests_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: navigation_menus id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_menus ALTER COLUMN id SET DEFAULT nextval('public.navigation_menus_id_seq'::regclass);


--
-- Name: navigation_revisions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_revisions ALTER COLUMN id SET DEFAULT nextval('public.navigation_revisions_id_seq'::regclass);


--
-- Name: not_found_hits id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.not_found_hits ALTER COLUMN id SET DEFAULT nextval('public.not_found_hits_id_seq'::regclass);


--
-- Name: order_items id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.order_items ALTER COLUMN id SET DEFAULT nextval('public.order_items_id_seq'::regclass);


--
-- Name: orders id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.orders ALTER COLUMN id SET DEFAULT nextval('public.orders_id_seq'::regclass);


--
-- Name: payment_events id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payment_events ALTER COLUMN id SET DEFAULT nextval('public.payment_events_id_seq'::regclass);


--
-- Name: payments id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payments ALTER COLUMN id SET DEFAULT nextval('public.payments_id_seq'::regclass);


--
-- Name: product_authors id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_authors ALTER COLUMN id SET DEFAULT nextval('public.product_authors_id_seq'::regclass);


--
-- Name: product_fact_evidence id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_fact_evidence ALTER COLUMN id SET DEFAULT nextval('public.product_fact_evidence_id_seq'::regclass);


--
-- Name: product_facts id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_facts ALTER COLUMN id SET DEFAULT nextval('public.product_facts_id_seq'::regclass);


--
-- Name: product_faqs id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_faqs ALTER COLUMN id SET DEFAULT nextval('public.product_faqs_id_seq'::regclass);


--
-- Name: product_files id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_files ALTER COLUMN id SET DEFAULT nextval('public.product_files_id_seq'::regclass);


--
-- Name: product_knowledge_facts id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_knowledge_facts ALTER COLUMN id SET DEFAULT nextval('public.product_knowledge_facts_id_seq'::regclass);


--
-- Name: product_layouts id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_layouts ALTER COLUMN id SET DEFAULT nextval('public.product_layouts_id_seq'::regclass);


--
-- Name: product_media id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_media ALTER COLUMN id SET DEFAULT nextval('public.product_media_id_seq'::regclass);


--
-- Name: product_prices id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_prices ALTER COLUMN id SET DEFAULT nextval('public.product_prices_id_seq'::regclass);


--
-- Name: product_seo id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_seo ALTER COLUMN id SET DEFAULT nextval('public.product_seo_id_seq'::regclass);


--
-- Name: product_translations id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations ALTER COLUMN id SET DEFAULT nextval('public.product_translations_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: quote_requests id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.quote_requests ALTER COLUMN id SET DEFAULT nextval('public.quote_requests_id_seq'::regclass);


--
-- Name: redirect_rules id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.redirect_rules ALTER COLUMN id SET DEFAULT nextval('public.redirect_rules_id_seq'::regclass);


--
-- Name: refunds id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.refunds ALTER COLUMN id SET DEFAULT nextval('public.refunds_id_seq'::regclass);


--
-- Name: review_aggregates id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_aggregates ALTER COLUMN id SET DEFAULT nextval('public.review_aggregates_id_seq'::regclass);


--
-- Name: review_category_scores id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_category_scores ALTER COLUMN id SET DEFAULT nextval('public.review_category_scores_id_seq'::regclass);


--
-- Name: review_media id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_media ALTER COLUMN id SET DEFAULT nextval('public.review_media_id_seq'::regclass);


--
-- Name: review_replies id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_replies ALTER COLUMN id SET DEFAULT nextval('public.review_replies_id_seq'::regclass);


--
-- Name: review_votes id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_votes ALTER COLUMN id SET DEFAULT nextval('public.review_votes_id_seq'::regclass);


--
-- Name: reviews id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews ALTER COLUMN id SET DEFAULT nextval('public.reviews_id_seq'::regclass);


--
-- Name: search_index_state id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_index_state ALTER COLUMN id SET DEFAULT nextval('public.search_index_state_id_seq'::regclass);


--
-- Name: search_queries id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_queries ALTER COLUMN id SET DEFAULT nextval('public.search_queries_id_seq'::regclass);


--
-- Name: search_synonyms id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_synonyms ALTER COLUMN id SET DEFAULT nextval('public.search_synonyms_id_seq'::regclass);


--
-- Name: semesters id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.semesters ALTER COLUMN id SET DEFAULT nextval('public.semesters_id_seq'::regclass);


--
-- Name: settings id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.settings ALTER COLUMN id SET DEFAULT nextval('public.settings_id_seq'::regclass);


--
-- Name: sitemap_url_states id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.sitemap_url_states ALTER COLUMN id SET DEFAULT nextval('public.sitemap_url_states_id_seq'::regclass);


--
-- Name: stages id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.stages ALTER COLUMN id SET DEFAULT nextval('public.stages_id_seq'::regclass);


--
-- Name: studio_entry_prefs id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_entry_prefs ALTER COLUMN id SET DEFAULT nextval('public.studio_entry_prefs_id_seq'::regclass);


--
-- Name: studio_media id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_media ALTER COLUMN id SET DEFAULT nextval('public.studio_media_id_seq'::regclass);


--
-- Name: studio_page_versions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_page_versions ALTER COLUMN id SET DEFAULT nextval('public.studio_page_versions_id_seq'::regclass);


--
-- Name: studio_pages id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_pages ALTER COLUMN id SET DEFAULT nextval('public.studio_pages_id_seq'::regclass);


--
-- Name: studio_symbol_versions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbol_versions ALTER COLUMN id SET DEFAULT nextval('public.studio_symbol_versions_id_seq'::regclass);


--
-- Name: studio_symbols id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbols ALTER COLUMN id SET DEFAULT nextval('public.studio_symbols_id_seq'::regclass);


--
-- Name: studio_templates id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_templates ALTER COLUMN id SET DEFAULT nextval('public.studio_templates_id_seq'::regclass);


--
-- Name: subjects id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.subjects ALTER COLUMN id SET DEFAULT nextval('public.subjects_id_seq'::regclass);


--
-- Name: theme_versions id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.theme_versions ALTER COLUMN id SET DEFAULT nextval('public.theme_versions_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Data for Name: admin_audit_log; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.admin_audit_log (id, admin_user_id, actor_email, action, entity_type, entity_id, before, after, reason, ip, created_at) FROM stdin;
1	1	owner@localhost	product.updated	product	26	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":null,"saleFrom":null,"saleTo":null}	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":1200,"saleFrom":"2026-08-31T21:00:00+03:00","saleTo":"2026-12-31T20:59:00+03:00"}	\N	172.21.0.1	2026-09-04 07:07:39
2	1	owner@localhost	product.updated	product	26	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":1200,"saleFrom":"2026-08-31T21:00:00+03:00","saleTo":"2026-12-31T20:59:00+03:00"}	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":1200,"saleFrom":"2026-09-01T00:00:00+03:00","saleTo":"2026-12-31T23:59:00+03:00"}	\N	172.21.0.1	2026-09-04 07:10:08
3	1	owner@localhost	studio.page.created	studio_page	1	\N	{"pageKey":"g","locale":"ar","name":"gg","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 07:10:15
4	1	owner@localhost	product.updated	product	26	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":1200,"saleFrom":"2026-09-01T00:00:00+03:00","saleTo":"2026-12-31T23:59:00+03:00"}	{"sku":"DESIGN-PREVIEW-24","slug":"\\u0648\\u0633\\u0627\\u0626\\u0644-\\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629-\\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629-\\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629-\\u0623\\u0648\\u0644-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","title":"\\u0648\\u0633\\u0627\\u0626\\u0644 \\u062a\\u0639\\u0644\\u064a\\u0645\\u064a\\u0629 \\u0627\\u0644\\u062a\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0628\\u062f\\u0646\\u064a\\u0629 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0623\\u0648\\u0644 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","status":"published","stageId":1,"gradeId":1,"subjectId":17,"semesterId":2,"regularMinor":1600,"saleMinor":null,"saleFrom":null,"saleTo":null}	\N	172.21.0.1	2026-09-04 07:10:30
5	1	owner@localhost	studio.page.created	studio_page	2	\N	{"pageKey":"tmp-verify","locale":"ar","name":"\\u0635\\u0641\\u062d\\u0629 \\u0644\\u0644\\u0627\\u062e\\u062a\\u0628\\u0627\\u0631","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 08:23:36
6	1	owner@localhost	studio.page.renamed	studio_page	2	{"name":"\\u0635\\u0641\\u062d\\u0629 \\u0644\\u0644\\u0627\\u062e\\u062a\\u0628\\u0627\\u0631"}	{"name":"\\u0627\\u0633\\u0645 \\u0628\\u0639\\u062f \\u0627\\u0644\\u062a\\u0633\\u0645\\u064a\\u0629"}	\N	172.21.0.1	2026-09-04 08:23:50
7	1	owner@localhost	studio.page.deleted	studio_page	2	{"pageKey":"tmp-verify","name":"\\u0627\\u0633\\u0645 \\u0628\\u0639\\u062f \\u0627\\u0644\\u062a\\u0633\\u0645\\u064a\\u0629","locale":"ar"}	\N	\N	172.21.0.1	2026-09-04 08:24:02
8	1	owner@localhost	crawl.requested	crawl	0	\N	\N	\N	172.21.0.1	2026-09-04 09:32:26
9	1	owner@localhost	i18n.locale.updated	locale	en	{"is_active":false,"position":1}	{"is_active":true,"position":1}	\N	172.21.0.1	2026-09-04 09:32:48
10	1	owner@localhost	i18n.locale.updated	locale	en	{"is_active":true,"position":1}	{"is_active":false,"position":1}	\N	172.21.0.1	2026-09-04 09:32:49
11	1	owner@localhost	i18n.locale.updated	locale	en	{"is_active":false,"position":1}	{"is_active":true,"position":1}	\N	172.21.0.1	2026-09-04 09:33:01
12	1	owner@localhost	studio.page.created	studio_page	3	\N	{"pageKey":"sync-check","locale":"ar","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0623\\u0633\\u0637\\u062d","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 09:36:51
13	1	owner@localhost	admin_user.created	admin_user	2	\N	{"id":2,"name":"\\u062d\\u0633\\u0627\\u0628 \\u062a\\u062d\\u0642\\u0651\\u0642 \\u0645\\u0624\\u0642\\u0651\\u062a","email":"verify-temp@localhost","role":"read_only","roleLabel":"\\u0642\\u0631\\u0627\\u0621\\u0629 \\u0641\\u0642\\u0637","lastLoginAt":null,"createdAt":"2026-09-04T11:50:00+03:00"}	\N	172.21.0.1	2026-09-04 11:50:00
14	1	owner@localhost	admin_user.role_changed	admin_user	2	{"id":2,"name":"\\u062d\\u0633\\u0627\\u0628 \\u062a\\u062d\\u0642\\u0651\\u0642 \\u0645\\u0624\\u0642\\u0651\\u062a","email":"verify-temp@localhost","role":"read_only","roleLabel":"\\u0642\\u0631\\u0627\\u0621\\u0629 \\u0641\\u0642\\u0637","lastLoginAt":null,"createdAt":"2026-09-04T11:50:00+03:00"}	{"id":2,"name":"\\u062d\\u0633\\u0627\\u0628 \\u062a\\u062d\\u0642\\u0651\\u0642 \\u0645\\u0624\\u0642\\u0651\\u062a","email":"verify-temp@localhost","role":"product_manager","roleLabel":"\\u0645\\u062f\\u064a\\u0631 \\u0627\\u0644\\u0645\\u0646\\u062a\\u062c\\u0627\\u062a","lastLoginAt":null,"createdAt":"2026-09-04T11:50:00+03:00"}	\N	172.21.0.1	2026-09-04 11:50:24
15	1	owner@localhost	admin_user.password_reset	admin_user	2	\N	\N	كلمة مرور جديدة عُيّنت من اللوحة.	172.21.0.1	2026-09-04 11:50:27
16	1	owner@localhost	admin_user.deleted	admin_user	2	{"id":2,"name":"\\u062d\\u0633\\u0627\\u0628 \\u062a\\u062d\\u0642\\u0651\\u0642 \\u0645\\u0624\\u0642\\u0651\\u062a","email":"verify-temp@localhost","role":"product_manager","roleLabel":"\\u0645\\u062f\\u064a\\u0631 \\u0627\\u0644\\u0645\\u0646\\u062a\\u062c\\u0627\\u062a","lastLoginAt":null,"createdAt":"2026-09-04T11:50:00+03:00"}	\N	\N	172.21.0.1	2026-09-04 11:50:29
17	1	owner@localhost	studio.template.created	studio_template	1	\N	{"name":"\\u0643\\u0627\\u0631\\u0648\\u0633\\u064a\\u0644 \\u0627\\u0644\\u0623\\u0643\\u062b\\u0631 \\u0645\\u0628\\u064a\\u0639\\u064b\\u0627","fromNode":true}	\N	172.21.0.1	2026-09-04 16:38:39
18	1	owner@localhost	design.hero.created	design_hero	1	\N	{"key":"homepage.main"}	\N	172.21.0.1	2026-09-04 16:57:09
19	1	owner@localhost	studio.page.published	studio_page	1	\N	{"pageKey":"g","version":15}	\N	172.21.0.1	2026-09-04 17:04:01
20	1	owner@localhost	studio.page.published	studio_page	1	\N	{"pageKey":"g","version":21}	\N	172.21.0.1	2026-09-04 18:02:03
21	1	owner@localhost	studio.page.published	studio_page	1	\N	{"pageKey":"g","version":24}	\N	172.21.0.1	2026-09-04 18:12:16
22	1	owner@localhost	studio.page.created	studio_page	4	\N	{"pageKey":"e2e-publish-check","locale":"ar","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 23:26:27
23	1	owner@localhost	studio.page.deleted	studio_page	4	{"pageKey":"e2e-publish-check","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","locale":"ar"}	\N	\N	172.21.0.1	2026-09-04 23:26:30
24	1	owner@localhost	studio.page.created	studio_page	5	\N	{"pageKey":"e2e-publish-check","locale":"ar","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 23:27:17
25	1	owner@localhost	studio.page.deleted	studio_page	5	{"pageKey":"e2e-publish-check","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","locale":"ar"}	\N	\N	172.21.0.1	2026-09-04 23:27:17
26	1	owner@localhost	studio.page.created	studio_page	6	\N	{"pageKey":"e2e-publish-check","locale":"ar","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-04 23:28:40
27	1	owner@localhost	studio.page.published	studio_page	6	\N	{"pageKey":"e2e-publish-check","version":2}	\N	172.21.0.1	2026-09-04 23:28:41
28	1	owner@localhost	studio.page.unpublished	studio_page	6	{"version":2}	\N	\N	172.21.0.1	2026-09-04 23:29:28
29	1	owner@localhost	studio.page.deleted	studio_page	6	{"pageKey":"e2e-publish-check","name":"\\u0641\\u062d\\u0635 \\u0627\\u0644\\u0646\\u0634\\u0631 \\u0627\\u0644\\u0622\\u0644\\u064a","locale":"ar"}	\N	\N	172.21.0.1	2026-09-04 23:29:28
30	1	owner@localhost	studio.page.created	studio_page	7	\N	{"pageKey":"disclosure-check","locale":"ar","name":"\\u0641\\u062d\\u0635 \\u0639\\u0627\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0643\\u0634\\u0641","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-05 00:28:50
31	1	owner@localhost	studio.page.published	studio_page	7	\N	{"pageKey":"disclosure-check","version":2}	\N	172.21.0.1	2026-09-05 00:28:51
32	1	owner@localhost	studio.page.unpublished	studio_page	7	{"version":2}	\N	\N	172.21.0.1	2026-09-05 00:31:30
33	1	owner@localhost	studio.page.deleted	studio_page	7	{"pageKey":"disclosure-check","name":"\\u0641\\u062d\\u0635 \\u0639\\u0627\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0643\\u0634\\u0641","locale":"ar"}	\N	\N	172.21.0.1	2026-09-05 00:31:30
34	1	owner@localhost	crawl.requested	crawl	0	\N	\N	\N	172.21.0.1	2026-09-06 07:20:03
35	1	owner@localhost	coupon.created	coupon	3	\N	{"code":"RTVAL-TMP-1","type":"percent","value":7,"usage_limit":3,"expires_at":null,"first_order_per_account":false}	\N	172.21.0.1	2026-09-06 07:33:03
36	1	owner@localhost	coupon.updated	coupon	3	{"code":"RTVAL-TMP-1","type":"percent","value":7,"usage_limit":3,"expires_at":null,"first_order_per_account":false}	{"code":"RTVAL-TMP-1","type":"percent","value":7,"usage_limit":9,"expires_at":null,"first_order_per_account":false}	\N	172.21.0.1	2026-09-06 07:33:06
37	1	owner@localhost	coupon.expired	coupon	3	\N	\N	\N	172.21.0.1	2026-09-06 07:33:09
38	1	owner@localhost	coupon.deleted	coupon	3	\N	\N	\N	172.21.0.1	2026-09-06 07:33:13
39	1	owner@localhost	studio.page.created	studio_page	8	\N	{"pageKey":"ff","locale":"ar","name":"\\u0634\\u0634","templateId":null,"fromPageKey":null}	\N	172.21.0.1	2026-09-10 22:35:41
\.


--
-- Data for Name: admin_notes; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.admin_notes (id, entity_type, entity_id, admin_user_id, author_email, body, edited_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: admin_users; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.admin_users (id, email, name, password_hash, role, last_login_at, created_at, updated_at) FROM stdin;
1	owner@localhost	مالك المتجر	$2y$12$yrnaGYjjTI6rcPQAmiZ4MexJew9YBxMuMCO9LIYRcYyrQMU6GNWOq	owner	2026-09-10 21:37:18	2026-09-04 06:21:56	2026-09-10 22:18:54
5	heshamg4mer@gmail.com	Hesham Gamer	$2y$12$Z2noCFgYbMTnXiZY6sDY9OuMMkQn7GMz8IrgAFIetyAZikEWFwrqy	owner	\N	2026-09-12 12:54:05	2026-09-12 12:54:05
6	arodhaditha@gmail.com	Arod Haditha	$2y$12$rpDelDMEva1zfaLCQ2aGwOdbX8onwVtWy0IKxCh/KNw0riWLVbOz2	owner	\N	2026-09-12 12:54:05	2026-09-12 12:54:05
4	Hishamalkaltham@gmail.com	Hisham AlKaltham	$2y$12$046rM2wTtl/mvaELJ1hhQe9bsTU5XZZrrnrlNKI.Jc1YX0E2UO5dS	owner	2026-09-13 05:18:43	2026-09-12 12:54:05	2026-09-13 05:18:43
3	rt-validate@localhost	Runtime Validation	$2y$12$T1six2SGDOeMTL.jU49vEOcO5/GrERRvPRIrmVLrH24FgkIaaCmDC	owner	2026-09-06 07:47:47	2026-09-06 07:02:32	2026-09-06 07:47:47
\.


--
-- Data for Name: ai_requests; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.ai_requests (id, provider, model, task, field, product_id, admin_user_id, actor_email, prompt_version, status, error_code, attempts, input_tokens, output_tokens, cost_minor, latency_ms, suggestions, created_at) FROM stdin;
\.


--
-- Data for Name: analytics_daily; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.analytics_daily (id, day, event_type, events, visitors, value_minor, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: analytics_events; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.analytics_events (id, occurred_at, event_type, visitor_hash, session_hash, product_id, order_id, value_minor, path, referrer_host, device, is_logged_in, meta, dedup_key, created_at, updated_at) FROM stdin;
1	2026-09-10 07:20:23	page_view	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	\N	\N	\N	\N	/shop	\N	desktop	f	\N	\N	2026-09-10 07:20:23	2026-09-10 07:20:23
2	2026-09-10 07:20:33	product_view	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	8c8ad3610a247b9b6665b9c2abc3cc041e607504d8ccf20cfffe548bfa9782a6	2	\N	\N	/products/dev-commerce-002	localhost	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:20:33	2026-09-10 07:20:33
3	2026-09-10 07:21:41	product_view	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	2	\N	\N	/products/dev-commerce-002	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:21:41	2026-09-10 07:21:41
4	2026-09-10 07:22:16	product_view	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	2	\N	\N	/products/dev-commerce-002	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:22:16	2026-09-10 07:22:16
5	2026-09-10 07:22:25	add_to_cart	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	2	\N	\N	/products/dev-commerce-002	127.0.0.1	desktop	f	{"source":"cta"}	\N	2026-09-10 07:22:25	2026-09-10 07:22:25
6	2026-09-10 07:22:35	checkout_start	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	\N	\N	\N	/checkout	127.0.0.1	desktop	f	{"source":"checkout"}	\N	2026-09-10 07:22:35	2026-09-10 07:22:35
7	2026-09-10 07:36:56	product_view	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	cbf230f76fee9ec3e2d014462264df99dd91668bd559bbd2f2a058f6246001ea	2	\N	\N	/products/dev-commerce-002	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:36:56	2026-09-10 07:36:56
8	2026-09-10 07:37:23	recommendation_click	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	d5fa2cf705b20bed4aa133c8df7629b72c3fea1d738080acf6c3fac00ca2a44d	3	\N	\N	/products/x	\N	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:37:23	2026-09-10 07:37:23
9	2026-09-10 07:37:23	product_view	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	d5fa2cf705b20bed4aa133c8df7629b72c3fea1d738080acf6c3fac00ca2a44d	3	\N	\N	/products/x	\N	desktop	f	\N	\N	2026-09-10 07:37:23	2026-09-10 07:37:23
10	2026-09-10 07:38:11	product_view	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	3	\N	\N	/products/تحضير-الرياضيات-سادس-ابتدائي-الفصل-الأول	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:38:11	2026-09-10 07:38:11
11	2026-09-10 07:39:12	recommendation_click	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	16	\N	\N	/products/تحضير-الرياضيات-سادس-ابتدائي-الفصل-الأول	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:39:12	2026-09-10 07:39:12
12	2026-09-10 07:39:13	product_view	584dcb0856bea4ffded222bc64eff7011f7a9e0ec7502a6387c27dd0131d8cf2	f9b7ab73c6c7f214de7512dbb9540191951a0047ff3f0802d721d6b79ca3accb	16	\N	\N	/products/أوراق-عمل-الرياضيات-أول-ابتدائي-الفصل-الأول	127.0.0.1	desktop	f	{"source":"pdp"}	\N	2026-09-10 07:39:13	2026-09-10 07:39:13
13	2026-09-09 04:00:08	purchase	\N	\N	\N	130	3200	\N	\N	\N	t	{"number":"TD-1130","items":1}	purchase:130	2026-09-10 09:59:32	2026-09-10 09:59:32
14	2026-09-09 04:00:08	purchase_item	\N	\N	10	130	3200	\N	\N	\N	t	\N	purchase:130:item:130	2026-09-10 09:59:32	2026-09-10 09:59:32
15	2026-09-09 04:26:24	purchase	\N	\N	\N	132	3200	\N	\N	\N	t	{"number":"TD-1132","items":1}	purchase:132	2026-09-10 09:59:32	2026-09-10 09:59:32
16	2026-09-09 04:26:24	purchase_item	\N	\N	10	132	3200	\N	\N	\N	t	\N	purchase:132:item:132	2026-09-10 09:59:32	2026-09-10 09:59:32
17	2026-09-09 04:51:07	purchase	\N	\N	\N	133	3200	\N	\N	\N	t	{"number":"TD-1133","items":1}	purchase:133	2026-09-10 09:59:32	2026-09-10 09:59:32
18	2026-09-09 04:51:07	purchase_item	\N	\N	10	133	3200	\N	\N	\N	t	\N	purchase:133:item:133	2026-09-10 09:59:32	2026-09-10 09:59:32
19	2026-09-09 04:58:27	purchase	\N	\N	\N	134	3200	\N	\N	\N	t	{"number":"TD-1134","items":1}	purchase:134	2026-09-10 09:59:32	2026-09-10 09:59:32
20	2026-09-09 04:58:27	purchase_item	\N	\N	10	134	3200	\N	\N	\N	t	\N	purchase:134:item:134	2026-09-10 09:59:32	2026-09-10 09:59:32
21	2026-09-10 04:25:28	purchase	\N	\N	\N	135	3200	\N	\N	\N	t	{"number":"TD-1135","items":1}	purchase:135	2026-09-10 09:59:32	2026-09-10 09:59:32
22	2026-09-10 04:25:28	purchase_item	\N	\N	10	135	3200	\N	\N	\N	t	\N	purchase:135:item:135	2026-09-10 09:59:32	2026-09-10 09:59:32
23	2026-09-10 04:29:09	purchase	\N	\N	\N	136	3200	\N	\N	\N	t	{"number":"TD-1136","items":1}	purchase:136	2026-09-10 09:59:32	2026-09-10 09:59:32
24	2026-09-10 04:29:09	purchase_item	\N	\N	10	136	3200	\N	\N	\N	t	\N	purchase:136:item:136	2026-09-10 09:59:32	2026-09-10 09:59:32
25	2026-09-10 04:35:07	purchase	\N	\N	\N	137	3200	\N	\N	\N	t	{"number":"TD-1137","items":1}	purchase:137	2026-09-10 09:59:32	2026-09-10 09:59:32
26	2026-09-10 04:35:07	purchase_item	\N	\N	10	137	3200	\N	\N	\N	t	\N	purchase:137:item:137	2026-09-10 09:59:32	2026-09-10 09:59:32
27	2026-09-10 04:51:54	purchase	\N	\N	\N	138	3200	\N	\N	\N	t	{"number":"TD-1138","items":1}	purchase:138	2026-09-10 09:59:32	2026-09-10 09:59:32
28	2026-09-10 04:51:54	purchase_item	\N	\N	10	138	3200	\N	\N	\N	t	\N	purchase:138:item:138	2026-09-10 09:59:32	2026-09-10 09:59:32
29	2026-09-10 04:59:59	purchase	\N	\N	\N	139	3200	\N	\N	\N	t	{"number":"TD-1139","items":1}	purchase:139	2026-09-10 09:59:32	2026-09-10 09:59:32
30	2026-09-10 04:59:59	purchase_item	\N	\N	10	139	3200	\N	\N	\N	t	\N	purchase:139:item:139	2026-09-10 09:59:32	2026-09-10 09:59:32
31	2026-09-10 05:01:42	purchase	\N	\N	\N	140	3200	\N	\N	\N	t	{"number":"TD-1140","items":1}	purchase:140	2026-09-10 09:59:32	2026-09-10 09:59:32
32	2026-09-10 05:01:42	purchase_item	\N	\N	10	140	3200	\N	\N	\N	t	\N	purchase:140:item:140	2026-09-10 09:59:32	2026-09-10 09:59:32
33	2026-09-10 05:04:11	purchase	\N	\N	\N	141	3200	\N	\N	\N	t	{"number":"TD-1141","items":1}	purchase:141	2026-09-10 09:59:32	2026-09-10 09:59:32
34	2026-09-10 05:04:11	purchase_item	\N	\N	10	141	3200	\N	\N	\N	t	\N	purchase:141:item:141	2026-09-10 09:59:32	2026-09-10 09:59:32
35	2026-09-10 05:07:54	purchase	\N	\N	\N	142	3200	\N	\N	\N	t	{"number":"TD-1142","items":1}	purchase:142	2026-09-10 09:59:32	2026-09-10 09:59:32
36	2026-09-10 05:07:54	purchase_item	\N	\N	10	142	3200	\N	\N	\N	t	\N	purchase:142:item:142	2026-09-10 09:59:32	2026-09-10 09:59:32
37	2026-09-10 05:09:43	purchase	\N	\N	\N	143	3200	\N	\N	\N	t	{"number":"TD-1143","items":1}	purchase:143	2026-09-10 09:59:32	2026-09-10 09:59:32
38	2026-09-10 05:09:43	purchase_item	\N	\N	10	143	3200	\N	\N	\N	t	\N	purchase:143:item:143	2026-09-10 09:59:32	2026-09-10 09:59:32
39	2026-09-10 05:11:32	purchase	\N	\N	\N	144	3200	\N	\N	\N	t	{"number":"TD-1144","items":1}	purchase:144	2026-09-10 09:59:32	2026-09-10 09:59:32
40	2026-09-10 05:11:32	purchase_item	\N	\N	10	144	3200	\N	\N	\N	t	\N	purchase:144:item:144	2026-09-10 09:59:32	2026-09-10 09:59:32
41	2026-09-10 05:21:47	purchase	\N	\N	\N	145	3200	\N	\N	\N	t	{"number":"TD-1145","items":1}	purchase:145	2026-09-10 09:59:32	2026-09-10 09:59:32
42	2026-09-10 05:21:47	purchase_item	\N	\N	10	145	3200	\N	\N	\N	t	\N	purchase:145:item:145	2026-09-10 09:59:32	2026-09-10 09:59:32
43	2026-09-10 05:33:48	purchase	\N	\N	\N	146	3200	\N	\N	\N	t	{"number":"TD-1146","items":1}	purchase:146	2026-09-10 09:59:32	2026-09-10 09:59:32
44	2026-09-10 05:33:48	purchase_item	\N	\N	10	146	3200	\N	\N	\N	t	\N	purchase:146:item:146	2026-09-10 09:59:32	2026-09-10 09:59:32
45	2026-09-10 05:47:37	purchase	\N	\N	\N	147	3200	\N	\N	\N	t	{"number":"TD-1147","items":1}	purchase:147	2026-09-10 09:59:32	2026-09-10 09:59:32
46	2026-09-10 05:47:37	purchase_item	\N	\N	10	147	3200	\N	\N	\N	t	\N	purchase:147:item:147	2026-09-10 09:59:32	2026-09-10 09:59:32
47	2026-09-10 05:54:36	purchase	\N	\N	\N	148	3200	\N	\N	\N	t	{"number":"TD-1148","items":1}	purchase:148	2026-09-10 09:59:33	2026-09-10 09:59:33
48	2026-09-10 05:54:36	purchase_item	\N	\N	10	148	3200	\N	\N	\N	t	\N	purchase:148:item:148	2026-09-10 09:59:33	2026-09-10 09:59:33
49	2026-09-10 05:56:11	purchase	\N	\N	\N	149	3200	\N	\N	\N	t	{"number":"TD-1149","items":1}	purchase:149	2026-09-10 09:59:33	2026-09-10 09:59:33
50	2026-09-10 05:56:11	purchase_item	\N	\N	10	149	3200	\N	\N	\N	t	\N	purchase:149:item:149	2026-09-10 09:59:33	2026-09-10 09:59:33
51	2026-09-10 06:03:39	purchase	\N	\N	\N	150	3200	\N	\N	\N	t	{"number":"TD-1150","items":1}	purchase:150	2026-09-10 09:59:33	2026-09-10 09:59:33
52	2026-09-10 06:03:39	purchase_item	\N	\N	10	150	3200	\N	\N	\N	t	\N	purchase:150:item:150	2026-09-10 09:59:33	2026-09-10 09:59:33
53	2026-09-10 06:05:13	purchase	\N	\N	\N	151	3200	\N	\N	\N	t	{"number":"TD-1151","items":1}	purchase:151	2026-09-10 09:59:33	2026-09-10 09:59:33
54	2026-09-10 06:05:13	purchase_item	\N	\N	10	151	3200	\N	\N	\N	t	\N	purchase:151:item:151	2026-09-10 09:59:33	2026-09-10 09:59:33
55	2026-09-10 06:06:36	purchase	\N	\N	\N	152	3200	\N	\N	\N	t	{"number":"TD-1152","items":1}	purchase:152	2026-09-10 09:59:33	2026-09-10 09:59:33
56	2026-09-10 06:06:36	purchase_item	\N	\N	10	152	3200	\N	\N	\N	t	\N	purchase:152:item:152	2026-09-10 09:59:33	2026-09-10 09:59:33
\.


--
-- Data for Name: analytics_product_daily; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.analytics_product_daily (id, day, product_id, views, add_to_carts, purchases, revenue_minor, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: asset_extractions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.asset_extractions (id, asset_id, asset_sha256, extractor, extractor_version, status, text, units, meta, error_code, duration_ms, bytes_read, created_at, updated_at) FROM stdin;
6	2	1a59bd34e139becdbf018f278e6981396160e57c9abd950d7c27e7ee7e5b38c6	plain_text	1.0.0	ok	المتجر: التحاضير الحديثة\nملف تطوير تجريبي — ليس محتوى حقيقيًا للبيع.\nالمنتج: DEV-COMMERCE-002\nالاسم المعروض: ملف تجريبي للتطوير — نموذج أوراق عمل.txt\nالغرض: إثبات مسار التحميل الآمن من طرفه إلى طرفه.\n	6	{"source_encoding":"UTF-8","line_count":6,"truncated":false,"text_bytes":345,"extractor":"plain_text","extractor_version":"1.0.0","identified":{"kind":"text","mime":"text\\/plain","extension":"txt","extension_mismatch":false,"confidence":0.8},"asset_id":2,"declared_mime":"text\\/plain"}	\N	0	345	2026-09-06 05:57:09	2026-09-06 05:57:09
4	5	d1016cf9bc8840af2a2a634ae8f363b7c0904e5a8a9f21cfd76c5a822cc75dbb	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":5,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4339	2026-09-06 05:56:59	2026-09-06 05:56:59
5	1	a0cf98d48be26ff1d2258cbb4e71240be1884d41fe309f39597617f355999de9	plain_text	1.0.0	ok	المتجر: التحاضير الحديثة\nملف تطوير تجريبي — ليس محتوى حقيقيًا للبيع.\nالمنتج: DEV-COMMERCE-001\nالاسم المعروض: ملف تجريبي للتطوير — نموذج تحضير.txt\nالغرض: إثبات مسار التحميل الآمن من طرفه إلى طرفه.\n	6	{"source_encoding":"UTF-8","line_count":6,"truncated":false,"text_bytes":338,"extractor":"plain_text","extractor_version":"1.0.0","identified":{"kind":"text","mime":"text\\/plain","extension":"txt","extension_mismatch":false,"confidence":0.8},"asset_id":1,"declared_mime":"text\\/plain"}	\N	0	338	2026-09-06 05:57:08	2026-09-06 05:57:08
7	3	ef4929f68fbcde6ba316bc24fbe5965e3b5682b06f6e1ef18adc1173ea66b825	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":3,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4280	2026-09-06 05:57:09	2026-09-06 05:57:09
8	4	27ab328515e1c5389762bc0d4787fc3ed80bdfecae732f65c79e22ef96e218f5	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":4,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4218	2026-09-06 05:57:09	2026-09-06 05:57:09
9	6	fb42557b76ad6c318a80c138dc994032589705d3bad90e4cc5bd9d55773d93f5	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":6,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4330	2026-09-06 05:57:09	2026-09-06 05:57:09
10	7	b71e103a54f2a577fa1b3a6e58ae23af89313cb9f307d5429f47a165a5a5b6ce	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":7,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4352	2026-09-06 05:57:09	2026-09-06 05:57:09
11	8	2567ae8261e190a5fa0251f47a4d4a25825945c75330a1c0ee19ae7f804578be	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":8,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4303	2026-09-06 05:57:09	2026-09-06 05:57:09
12	9	9e92ba8359b832d74947c4bc5e45f9be70a1aa07f19191c335eba2fa33912952	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":9,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4211	2026-09-06 05:57:09	2026-09-06 05:57:09
13	10	9920907be227591f17565f73905355a513d655b191518dc3c1a620bac529c3a8	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":10,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4351	2026-09-06 05:57:09	2026-09-06 05:57:09
14	11	d9855e521e559b1a1b90efdb90128aa76cdf856eb3f4c864fc3129b771bc77e4	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":11,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4382	2026-09-06 05:57:09	2026-09-06 05:57:09
15	12	dd72dfacfbf181d2c3f2abe46d46dfde1bbb45761a3182542d006ff265fb0a4d	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":12,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4330	2026-09-06 05:57:09	2026-09-06 05:57:09
16	13	40d63056ba9fd2704ec133975154bac23568d0e1791dd5b8904244d2f544a809	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":13,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4315	2026-09-06 05:57:09	2026-09-06 05:57:09
17	14	be00b446f2f9528a897824f2f77b787ef045541fb95944f9b2c4793eef9f5523	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":14,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4276	2026-09-06 05:57:09	2026-09-06 05:57:09
18	15	957b459b98919df3d9c094737a2ad7a645a6c191ab8aa8829d3548aea940e958	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":15,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4232	2026-09-06 05:57:09	2026-09-06 05:57:09
19	16	d9c4a90948a2ae0114fa13de997e06a23514d258049cb6473415ce8036992e1f	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":16,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4355	2026-09-06 05:57:09	2026-09-06 05:57:09
20	17	025b4b2d5699940f563183b200ccd11236200589cf9d90e25758b10c01e5614b	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":17,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4272	2026-09-06 05:57:09	2026-09-06 05:57:09
21	18	561487527771b001e3e9253be2956668325af3133ae1011d2d3ed3674ba1710b	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":18,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4310	2026-09-06 05:57:09	2026-09-06 05:57:09
22	19	83f739e8dc620290b1b994af00a4f31d177e77c9a300402d16bb91df41397e37	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":19,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4390	2026-09-06 05:57:09	2026-09-06 05:57:09
23	20	f38c6007cb7cfe7c13d55d097d33c75642cfb69cfd3c159c8129efcc52d9ad7f	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":20,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4356	2026-09-06 05:57:09	2026-09-06 05:57:09
24	21	341fea472ac85acf03b1f26674abfdb6296b134781dddf4077429c47c6d31ed3	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":21,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4327	2026-09-06 05:57:09	2026-09-06 05:57:09
25	22	e52a9c35633bdff00c5e6de726ca92ddc565428ddca4f321ff70a99fbff9dc4c	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":22,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4260	2026-09-06 05:57:09	2026-09-06 05:57:09
26	23	499b036c48b31c095e3fb0ea411eb4dba2bea0ab6189357d966cf1e1d8980223	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":23,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4385	2026-09-06 05:57:09	2026-09-06 05:57:09
27	24	b350553a9bb79e5399aad1ee1265f5aaa89aceacd71ae325d3850efbfb365d0b	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":24,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4290	2026-09-06 05:57:09	2026-09-06 05:57:09
28	25	304ff80404a22d0e9f2ba3d1a14570548025fc0a0d1f51270599d42264726483	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":25,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4290	2026-09-06 05:57:09	2026-09-06 05:57:09
29	26	d54a2ae8c95916a8e877b5cfc0c39f4400fcacf023c9328517db149f9fe02328	capability_gap	1.0.0	ocr_unavailable	\N	\N	{"capability":"image_ocr","probe":{"tesseract":false,"ext-gd":false,"ext-imagick":false},"requires":["tesseract-ocr","\\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629 tesseract-ocr-ara","ext-gd \\u0623\\u0648 ext-imagick"],"note_ar":"\\u0627\\u0644\\u0635\\u0648\\u0631\\u0629 \\u0644\\u0645 \\u062a\\u064f\\u0642\\u0631\\u0623 \\u0648\\u0644\\u0645 \\u064a\\u064f\\u062f\\u0651\\u0639\\u064e \\u0641\\u0647\\u0645\\u0647\\u0627: \\u0644\\u0627 \\u0645\\u062d\\u0631\\u0651\\u0643 OCR \\u0641\\u064a \\u0627\\u0644\\u062d\\u0627\\u0648\\u064a\\u0629. \\u0627\\u0644\\u0645\\u0637\\u0644\\u0648\\u0628 \\u062a\\u062b\\u0628\\u064a\\u062a tesseract-ocr \\u0645\\u0639 \\u062d\\u0632\\u0645\\u0629 \\u0627\\u0644\\u0644\\u063a\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0651\\u0629\\u060c \\u0623\\u0648 \\u062a\\u0648\\u0635\\u064a\\u0644 \\u062e\\u062f\\u0645\\u0629 \\u0631\\u0624\\u064a\\u0629 \\u062e\\u0627\\u0631\\u062c\\u064a\\u0651\\u0629.","extractor":"capability_gap","extractor_version":"1.0.0","identified":{"kind":"image","mime":"application\\/octet-stream","extension":"svg","extension_mismatch":false,"confidence":0.8},"asset_id":26,"declared_mime":"image\\/svg+xml"}	ocr_engine_missing	0	4390	2026-09-06 05:57:09	2026-09-06 05:57:09
\.


--
-- Data for Name: assets; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.assets (id, provider, provider_ref, original_name, customer_label, sha256, size_bytes, mime, visibility, usage_count, created_at, updated_at) FROM stdin;
1	local	assets/a0/cf/a0cf98d48be26ff1d2258cbb4e71240be1884d41fe309f39597617f355999de9.txt	ملف تجريبي للتطوير — نموذج تحضير.txt	ملف تجريبي للتطوير — نموذج تحضير.txt	a0cf98d48be26ff1d2258cbb4e71240be1884d41fe309f39597617f355999de9	338	text/plain	paid_private	1	2026-09-04 06:01:24	2026-09-04 06:01:24
2	local	assets/1a/59/1a59bd34e139becdbf018f278e6981396160e57c9abd950d7c27e7ee7e5b38c6.txt	ملف تجريبي للتطوير — نموذج أوراق عمل.txt	ملف تجريبي للتطوير — نموذج أوراق عمل.txt	1a59bd34e139becdbf018f278e6981396160e57c9abd950d7c27e7ee7e5b38c6	345	text/plain	paid_private	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	local	assets/ef/49/ef4929f68fbcde6ba316bc24fbe5965e3b5682b06f6e1ef18adc1173ea66b825.svg	DESIGN-PREVIEW-01.svg	غلاف معاينة — تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول.svg	ef4929f68fbcde6ba316bc24fbe5965e3b5682b06f6e1ef18adc1173ea66b825	4280	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
4	local	assets/27/ab/27ab328515e1c5389762bc0d4787fc3ed80bdfecae732f65c79e22ef96e218f5.svg	DESIGN-PREVIEW-02.svg	غلاف معاينة — عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني.svg	27ab328515e1c5389762bc0d4787fc3ed80bdfecae732f65c79e22ef96e218f5	4218	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
5	local	assets/d1/01/d1016cf9bc8840af2a2a634ae8f363b7c0904e5a8a9f21cfd76c5a822cc75dbb.svg	DESIGN-PREVIEW-03.svg	غلاف معاينة — أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول.svg	d1016cf9bc8840af2a2a634ae8f363b7c0904e5a8a9f21cfd76c5a822cc75dbb	4339	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
6	local	assets/fb/42/fb42557b76ad6c318a80c138dc994032589705d3bad90e4cc5bd9d55773d93f5.svg	DESIGN-PREVIEW-04.svg	غلاف معاينة — اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث.svg	fb42557b76ad6c318a80c138dc994032589705d3bad90e4cc5bd9d55773d93f5	4330	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
7	local	assets/b7/1e/b71e103a54f2a577fa1b3a6e58ae23af89313cb9f307d5429f47a165a5a5b6ce.svg	DESIGN-PREVIEW-05.svg	غلاف معاينة — توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول.svg	b71e103a54f2a577fa1b3a6e58ae23af89313cb9f307d5429f47a165a5a5b6ce	4352	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
8	local	assets/25/67/2567ae8261e190a5fa0251f47a4d4a25825945c75330a1c0ee19ae7f804578be.svg	DESIGN-PREVIEW-06.svg	غلاف معاينة — تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني.svg	2567ae8261e190a5fa0251f47a4d4a25825945c75330a1c0ee19ae7f804578be	4303	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
9	local	assets/9e/92/9e92ba8359b832d74947c4bc5e45f9be70a1aa07f19191c335eba2fa33912952.svg	DESIGN-PREVIEW-07.svg	غلاف معاينة — عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول.svg	9e92ba8359b832d74947c4bc5e45f9be70a1aa07f19191c335eba2fa33912952	4211	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
10	local	assets/99/20/9920907be227591f17565f73905355a513d655b191518dc3c1a620bac529c3a8.svg	DESIGN-PREVIEW-08.svg	غلاف معاينة — أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني.svg	9920907be227591f17565f73905355a513d655b191518dc3c1a620bac529c3a8	4351	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
11	local	assets/d9/85/d9855e521e559b1a1b90efdb90128aa76cdf856eb3f4c864fc3129b771bc77e4.svg	DESIGN-PREVIEW-09.svg	غلاف معاينة — وسائل تعليمية القرآن الكريم — الصف الثاني الابتدائي — الفصل الدراسي الأول.svg	d9855e521e559b1a1b90efdb90128aa76cdf856eb3f4c864fc3129b771bc77e4	4382	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
12	local	assets/dd/72/dd72dfacfbf181d2c3f2abe46d46dfde1bbb45761a3182542d006ff265fb0a4d.svg	DESIGN-PREVIEW-10.svg	غلاف معاينة — حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث.svg	dd72dfacfbf181d2c3f2abe46d46dfde1bbb45761a3182542d006ff265fb0a4d	4330	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
13	local	assets/40/d6/40d63056ba9fd2704ec133975154bac23568d0e1791dd5b8904244d2f544a809.svg	DESIGN-PREVIEW-11.svg	غلاف معاينة — تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني.svg	40d63056ba9fd2704ec133975154bac23568d0e1791dd5b8904244d2f544a809	4315	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
14	local	assets/be/00/be00b446f2f9528a897824f2f77b787ef045541fb95944f9b2c4793eef9f5523.svg	DESIGN-PREVIEW-12.svg	غلاف معاينة — اختبارات الأحياء — الصف الثالث الثانوي — الفصل الدراسي الأول.svg	be00b446f2f9528a897824f2f77b787ef045541fb95944f9b2c4793eef9f5523	4276	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
15	local	assets/95/7b/957b459b98919df3d9c094737a2ad7a645a6c191ab8aa8829d3548aea940e958.svg	DESIGN-PREVIEW-13.svg	غلاف معاينة — عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني.svg	957b459b98919df3d9c094737a2ad7a645a6c191ab8aa8829d3548aea940e958	4232	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
16	local	assets/d9/c4/d9c4a90948a2ae0114fa13de997e06a23514d258049cb6473415ce8036992e1f.svg	DESIGN-PREVIEW-14.svg	غلاف معاينة — أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول.svg	d9c4a90948a2ae0114fa13de997e06a23514d258049cb6473415ce8036992e1f	4355	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
17	local	assets/02/5b/025b4b2d5699940f563183b200ccd11236200589cf9d90e25758b10c01e5614b.svg	DESIGN-PREVIEW-15.svg	غلاف معاينة — تحضير العلوم — الصف الخامس الابتدائي — الفصل الدراسي الثالث.svg	025b4b2d5699940f563183b200ccd11236200589cf9d90e25758b10c01e5614b	4272	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
18	local	assets/56/14/561487527771b001e3e9253be2956668325af3133ae1011d2d3ed3674ba1710b.svg	DESIGN-PREVIEW-16.svg	غلاف معاينة — اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول.svg	561487527771b001e3e9253be2956668325af3133ae1011d2d3ed3674ba1710b	4310	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
19	local	assets/83/f7/83f739e8dc620290b1b994af00a4f31d177e77c9a300402d16bb91df41397e37.svg	DESIGN-PREVIEW-17.svg	غلاف معاينة — وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني.svg	83f739e8dc620290b1b994af00a4f31d177e77c9a300402d16bb91df41397e37	4390	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
20	local	assets/f3/8c/f38c6007cb7cfe7c13d55d097d33c75642cfb69cfd3c159c8129efcc52d9ad7f.svg	DESIGN-PREVIEW-18.svg	غلاف معاينة — توزيع الكفايات اللغوية — الصف الثاني الثانوي — الفصل الدراسي الأول.svg	f38c6007cb7cfe7c13d55d097d33c75642cfb69cfd3c159c8129efcc52d9ad7f	4356	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
21	local	assets/34/1f/341fea472ac85acf03b1f26674abfdb6296b134781dddf4077429c47c6d31ed3.svg	DESIGN-PREVIEW-19.svg	غلاف معاينة — حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث.svg	341fea472ac85acf03b1f26674abfdb6296b134781dddf4077429c47c6d31ed3	4327	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
22	local	assets/e5/2a/e52a9c35633bdff00c5e6de726ca92ddc565428ddca4f321ff70a99fbff9dc4c.svg	DESIGN-PREVIEW-20.svg	غلاف معاينة — عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول.svg	e52a9c35633bdff00c5e6de726ca92ddc565428ddca4f321ff70a99fbff9dc4c	4260	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
23	local	assets/49/9b/499b036c48b31c095e3fb0ea411eb4dba2bea0ab6189357d966cf1e1d8980223.svg	DESIGN-PREVIEW-21.svg	غلاف معاينة — أوراق عمل المهارات الحياتية — الصف الثاني المتوسط — الفصل الدراسي الثالث.svg	499b036c48b31c095e3fb0ea411eb4dba2bea0ab6189357d966cf1e1d8980223	4385	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
24	local	assets/b3/50/b350553a9bb79e5399aad1ee1265f5aaa89aceacd71ae325d3850efbfb365d0b.svg	DESIGN-PREVIEW-22.svg	غلاف معاينة — تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني.svg	b350553a9bb79e5399aad1ee1265f5aaa89aceacd71ae325d3850efbfb365d0b	4290	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
25	local	assets/30/4f/304ff80404a22d0e9f2ba3d1a14570548025fc0a0d1f51270599d42264726483.svg	DESIGN-PREVIEW-23.svg	غلاف معاينة — اختبارات علم البيئة — الصف الثاني الثانوي — الفصل الدراسي الثالث.svg	304ff80404a22d0e9f2ba3d1a14570548025fc0a0d1f51270599d42264726483	4290	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
26	local	assets/d5/4a/d54a2ae8c95916a8e877b5cfc0c39f4400fcacf023c9328517db149f9fe02328.svg	DESIGN-PREVIEW-24.svg	غلاف معاينة — وسائل تعليمية التربية البدنية — الصف الأول الابتدائي — الفصل الدراسي الثاني.svg	d54a2ae8c95916a8e877b5cfc0c39f4400fcacf023c9328517db149f9fe02328	4390	image/svg+xml	public_sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
\.


--
-- Data for Name: auth_identities; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.auth_identities (id, customer_id, provider, provider_subject, verified_email, created_at, updated_at) FROM stdin;
2	15	magic	e2e-1788909310333@example.com	e2e-1788909310333@example.com	2026-09-09 02:15:16	2026-09-09 02:15:16
3	17	magic	e2e-1788909345802@example.com	e2e-1788909345802@example.com	2026-09-09 02:15:48	2026-09-09 02:15:48
4	19	magic	e2e-1788909419629@example.com	e2e-1788909419629@example.com	2026-09-09 02:17:01	2026-09-09 02:17:01
6	21	magic	e2e-1788909436606@example.com	e2e-1788909436606@example.com	2026-09-09 02:17:19	2026-09-09 02:17:19
8	33	magic	e2e-1788918999455@example.com	e2e-1788918999455@example.com	2026-09-09 04:56:42	2026-09-09 04:56:42
10	36	magic	e2e-1789003380828@example.com	e2e-1789003380828@example.com	2026-09-10 04:23:03	2026-09-10 04:23:03
11	38	magic	e2e-1789003624153@example.com	e2e-1789003624153@example.com	2026-09-10 04:27:06	2026-09-10 04:27:06
12	39	magic	e2e-1789003632594@example.com	e2e-1789003632594@example.com	2026-09-10 04:27:14	2026-09-10 04:27:14
13	40	magic	e2e-1789003639997@example.com	e2e-1789003639997@example.com	2026-09-10 04:27:22	2026-09-10 04:27:22
14	41	magic	e2e-1789003660077@example.com	e2e-1789003660077@example.com	2026-09-10 04:27:42	2026-09-10 04:27:42
15	43	magic	e2e-1789004015378@example.com	e2e-1789004015378@example.com	2026-09-10 04:33:38	2026-09-10 04:33:38
16	45	magic	e2e-1789004839857@example.com	e2e-1789004839857@example.com	2026-09-10 04:47:22	2026-09-10 04:47:22
17	47	magic	e2e-1789005500611@example.com	e2e-1789005500611@example.com	2026-09-10 04:58:23	2026-09-10 04:58:23
18	49	magic	e2e-1789005603181@example.com	e2e-1789005603181@example.com	2026-09-10 05:00:05	2026-09-10 05:00:05
19	51	magic	e2e-1789005741616@example.com	e2e-1789005741616@example.com	2026-09-10 05:02:24	2026-09-10 05:02:24
20	53	magic	e2e-1789005968892@example.com	e2e-1789005968892@example.com	2026-09-10 05:06:11	2026-09-10 05:06:11
21	55	magic	e2e-1789006079857@example.com	e2e-1789006079857@example.com	2026-09-10 05:08:02	2026-09-10 05:08:02
22	57	magic	e2e-1789006187663@example.com	e2e-1789006187663@example.com	2026-09-10 05:09:50	2026-09-10 05:09:50
23	59	magic	e2e-1789006797869@example.com	e2e-1789006797869@example.com	2026-09-10 05:20:00	2026-09-10 05:20:00
24	61	magic	e2e-1789007542780@example.com	e2e-1789007542780@example.com	2026-09-10 05:32:25	2026-09-10 05:32:25
25	64	magic	e2e-1789008784193@example.com	e2e-1789008784193@example.com	2026-09-10 05:53:07	2026-09-10 05:53:07
26	66	magic	e2e-1789008881202@example.com	e2e-1789008881202@example.com	2026-09-10 05:54:43	2026-09-10 05:54:43
27	68	magic	e2e-1789009335141@example.com	e2e-1789009335141@example.com	2026-09-10 06:02:17	2026-09-10 06:02:17
28	70	magic	e2e-1789009425733@example.com	e2e-1789009425733@example.com	2026-09-10 06:03:50	2026-09-10 06:03:50
29	72	magic	e2e-1789009516066@example.com	e2e-1789009516066@example.com	2026-09-10 06:05:18	2026-09-10 06:05:18
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: campaigns; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.campaigns (id, title, description, url, starts_at, ends_at, created_at, updated_at) FROM stdin;
1	عروض بداية الفصل الدراسي	خصومات على تحاضير وعروض الفصل الأول لعدد محدود من الأيام.	/offers	2026-09-02 06:01:26	2026-09-16 06:01:26	2026-09-04 06:01:26	2026-09-04 06:01:26
\.


--
-- Data for Name: cart_items; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.cart_items (id, cart_id, product_id, qty, unit_price_snapshot_minor, created_at, updated_at) FROM stdin;
1	1	2	1	25000	2026-09-06 07:05:01	2026-09-06 07:05:01
2	2	2	1	25000	2026-09-06 07:05:09	2026-09-06 07:05:09
3	3	2	1	25000	2026-09-06 07:06:29	2026-09-06 07:06:29
5	5	2	1	25000	2026-09-06 07:13:39	2026-09-06 07:13:39
6	6	2	1	25000	2026-09-06 07:15:22	2026-09-06 07:15:22
8	8	2	1	25000	2026-09-06 07:17:11	2026-09-06 07:17:11
9	9	10	1	3200	2026-09-09 00:49:11	2026-09-09 00:49:11
10	10	10	1	3200	2026-09-09 00:49:46	2026-09-09 00:49:46
11	11	10	1	3200	2026-09-09 00:51:03	2026-09-09 00:51:03
12	12	10	1	3200	2026-09-09 00:51:44	2026-09-09 00:51:44
13	13	10	1	3200	2026-09-09 00:51:59	2026-09-09 00:51:59
14	14	10	1	3200	2026-09-09 00:52:40	2026-09-09 00:52:40
15	15	10	1	3200	2026-09-09 00:52:54	2026-09-09 00:52:54
16	16	10	1	3200	2026-09-09 00:53:28	2026-09-09 00:53:28
17	17	10	1	3200	2026-09-09 00:53:32	2026-09-09 00:53:32
18	18	10	1	3200	2026-09-09 00:54:06	2026-09-09 00:54:06
19	19	10	1	3200	2026-09-09 00:54:54	2026-09-09 00:54:54
20	20	10	1	3200	2026-09-09 00:55:36	2026-09-09 00:55:36
21	21	10	1	3200	2026-09-09 01:01:30	2026-09-09 01:01:30
22	22	10	1	3200	2026-09-09 01:02:14	2026-09-09 01:02:14
23	23	10	1	3200	2026-09-09 01:02:56	2026-09-09 01:02:56
24	24	10	1	3200	2026-09-09 01:04:23	2026-09-09 01:04:23
25	25	10	1	3200	2026-09-09 01:04:27	2026-09-09 01:04:27
26	26	10	1	3200	2026-09-09 01:04:31	2026-09-09 01:04:31
27	27	10	1	3200	2026-09-09 01:04:36	2026-09-09 01:04:36
28	28	10	1	3200	2026-09-09 01:04:40	2026-09-09 01:04:40
29	29	10	1	3200	2026-09-09 01:04:43	2026-09-09 01:04:43
30	30	10	1	3200	2026-09-09 01:04:47	2026-09-09 01:04:47
31	31	10	1	3200	2026-09-09 01:04:51	2026-09-09 01:04:51
32	32	10	1	3200	2026-09-09 01:04:54	2026-09-09 01:04:54
33	33	10	1	3200	2026-09-09 01:08:31	2026-09-09 01:08:31
42	42	2	1	25000	2026-09-09 04:09:47	2026-09-09 04:09:47
43	43	2	1	25000	2026-09-09 04:10:23	2026-09-09 04:10:23
44	44	10	1	3200	2026-09-09 04:25:35	2026-09-09 04:25:35
45	45	10	1	3200	2026-09-09 04:26:15	2026-09-09 04:26:15
47	47	10	1	3200	2026-09-09 04:27:45	2026-09-09 04:27:45
48	48	10	1	3200	2026-09-09 04:51:02	2026-09-09 04:51:02
50	50	10	1	3200	2026-09-09 04:57:00	2026-09-09 04:57:00
52	52	2	1	25000	2026-09-09 05:00:50	2026-09-09 05:00:50
53	53	10	1	3200	2026-09-10 04:24:18	2026-09-10 04:24:18
54	54	2	1	25000	2026-09-10 04:24:41	2026-09-10 04:24:41
56	56	10	1	3200	2026-09-10 04:28:04	2026-09-10 04:28:04
57	57	2	1	25000	2026-09-10 04:28:24	2026-09-10 04:28:24
59	59	10	1	3200	2026-09-10 04:33:57	2026-09-10 04:33:57
60	60	2	1	25000	2026-09-10 04:34:21	2026-09-10 04:34:21
62	62	10	1	3200	2026-09-10 04:49:35	2026-09-10 04:49:35
63	63	2	1	25000	2026-09-10 04:51:10	2026-09-10 04:51:10
65	65	10	1	3200	2026-09-10 04:58:39	2026-09-10 04:58:39
66	66	2	1	25000	2026-09-10 04:59:08	2026-09-10 04:59:08
68	68	10	1	3200	2026-09-10 05:00:26	2026-09-10 05:00:26
69	69	2	1	25000	2026-09-10 05:00:51	2026-09-10 05:00:51
71	71	10	1	3200	2026-09-10 05:02:42	2026-09-10 05:02:42
72	72	2	1	25000	2026-09-10 05:03:10	2026-09-10 05:03:10
74	74	2	1	25000	2026-09-10 05:04:55	2026-09-10 05:04:55
75	75	2	1	25000	2026-09-10 05:04:59	2026-09-10 05:04:59
76	76	2	1	25000	2026-09-10 05:05:13	2026-09-10 05:05:13
77	77	2	1	25000	2026-09-10 05:05:51	2026-09-10 05:05:51
78	78	2	1	25000	2026-09-10 05:05:55	2026-09-10 05:05:55
79	79	2	1	25000	2026-09-10 05:05:59	2026-09-10 05:05:59
80	80	10	1	3200	2026-09-10 05:06:30	2026-09-10 05:06:30
81	81	2	1	25000	2026-09-10 05:07:02	2026-09-10 05:07:02
83	83	10	1	3200	2026-09-10 05:08:26	2026-09-10 05:08:26
84	84	2	1	25000	2026-09-10 05:08:53	2026-09-10 05:08:53
86	86	10	1	3200	2026-09-10 05:10:08	2026-09-10 05:10:08
87	87	2	1	25000	2026-09-10 05:10:38	2026-09-10 05:10:38
89	89	10	1	3200	2026-09-10 05:20:22	2026-09-10 05:20:22
90	90	2	1	25000	2026-09-10 05:20:55	2026-09-10 05:20:55
92	92	10	1	3200	2026-09-10 05:32:44	2026-09-10 05:32:44
93	93	2	1	25000	2026-09-10 05:33:05	2026-09-10 05:33:05
96	96	10	1	3200	2026-09-10 05:53:25	2026-09-10 05:53:25
97	97	2	1	25000	2026-09-10 05:53:53	2026-09-10 05:53:53
99	99	10	1	3200	2026-09-10 05:55:01	2026-09-10 05:55:01
100	100	2	1	25000	2026-09-10 05:55:24	2026-09-10 05:55:24
102	102	10	1	3200	2026-09-10 06:02:33	2026-09-10 06:02:33
103	103	2	1	25000	2026-09-10 06:02:56	2026-09-10 06:02:56
105	105	10	1	3200	2026-09-10 06:04:07	2026-09-10 06:04:07
106	106	2	1	25000	2026-09-10 06:04:32	2026-09-10 06:04:32
108	108	10	1	3200	2026-09-10 06:05:33	2026-09-10 06:05:33
109	109	2	1	25000	2026-09-10 06:05:56	2026-09-10 06:05:56
111	111	2	1	25000	2026-09-10 07:22:25	2026-09-10 07:22:25
113	113	2	1	25000	2026-09-12 11:17:51	2026-09-12 11:17:51
114	114	2	1	25000	2026-09-13 05:14:01	2026-09-13 05:14:01
\.


--
-- Data for Name: carts; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.carts (id, customer_id, token, created_at, updated_at, coupon_id) FROM stdin;
1	\N	159c750e-dc08-4c76-ada9-036769a62b23	2026-09-06 07:05:01	2026-09-06 07:05:01	\N
2	\N	19a075c7-8ccd-4a3e-922c-4d876d2dccc7	2026-09-06 07:05:09	2026-09-06 07:05:09	\N
3	\N	c581bc13-5a64-4aa1-bcba-f688331d0491	2026-09-06 07:06:29	2026-09-06 07:06:29	\N
4	\N	7a333bdd-c1b9-49b1-96d8-8f1c258fd862	2026-09-06 07:13:28	2026-09-06 07:13:28	\N
5	\N	15b927e1-223b-4920-8aa4-e630c22a0d0b	2026-09-06 07:13:39	2026-09-06 07:13:39	\N
6	\N	9acd807e-d0ba-4011-a9a8-3906b50bc893	2026-09-06 07:15:22	2026-09-06 07:15:22	\N
7	\N	a079ffdb-f178-49f1-ac2b-ef8b3aaee27f	2026-09-06 07:16:49	2026-09-06 07:16:49	\N
8	\N	9441f3ab-b8ef-4091-8aa1-028f28ec701c	2026-09-06 07:17:10	2026-09-06 07:17:10	\N
9	\N	f13c91b7-5381-45c9-a6b3-64a798c24b85	2026-09-09 00:49:11	2026-09-09 00:49:11	\N
10	\N	954e13fe-eaee-4c99-8b30-bf2dec89a7e0	2026-09-09 00:49:46	2026-09-09 00:49:46	\N
11	\N	c710b8bb-e3ae-43e6-b836-2143c8d8d7a9	2026-09-09 00:51:03	2026-09-09 00:51:03	\N
12	\N	51e5b383-beea-4f91-b682-f154195c7fae	2026-09-09 00:51:44	2026-09-09 00:51:44	\N
13	\N	ae323f3b-488e-46de-b1a0-16eea38ed06b	2026-09-09 00:51:59	2026-09-09 00:51:59	\N
14	\N	2f0590ce-84a2-4616-9302-60f0bd12747e	2026-09-09 00:52:40	2026-09-09 00:52:40	\N
15	\N	ea44cbc8-1fd6-4dec-9d00-66266051d627	2026-09-09 00:52:54	2026-09-09 00:52:54	\N
16	\N	8a657304-b9da-49d4-9ebb-d008f65320a7	2026-09-09 00:53:28	2026-09-09 00:53:28	\N
17	\N	7404e63e-a64e-4414-ae34-631395a34bd6	2026-09-09 00:53:32	2026-09-09 00:53:32	\N
18	\N	b15c6ce9-d6cd-4806-a4b0-464ce5a865fa	2026-09-09 00:54:06	2026-09-09 00:54:06	\N
19	\N	99792cfd-454e-49df-9bc0-5d4ec8eeeef5	2026-09-09 00:54:54	2026-09-09 00:54:54	\N
20	\N	1dff5462-fa79-4bcc-abad-c4b88e5babe9	2026-09-09 00:55:36	2026-09-09 00:55:36	\N
21	\N	6d23fd85-74e3-43c8-9227-cc708fb041d6	2026-09-09 01:01:30	2026-09-09 01:01:30	\N
22	\N	aa7c63cf-ae72-482a-b1ce-f678048ea2f7	2026-09-09 01:02:14	2026-09-09 01:02:14	\N
23	\N	4727ec58-6de9-4cd5-9350-3935705c3343	2026-09-09 01:02:56	2026-09-09 01:02:56	\N
24	\N	8c2d705b-7c1b-434e-a8ff-70ec8257906a	2026-09-09 01:04:23	2026-09-09 01:04:23	\N
25	\N	950dfb32-25ff-496c-aa26-a51ec124f640	2026-09-09 01:04:27	2026-09-09 01:04:27	\N
26	\N	d046a625-b2c2-4bef-865c-97714d0327a8	2026-09-09 01:04:31	2026-09-09 01:04:31	\N
27	\N	e4581e64-1a19-46b9-a4b8-28d43d12edbc	2026-09-09 01:04:36	2026-09-09 01:04:36	\N
28	\N	cde0e609-bf2f-495f-adf4-81a107ee73c0	2026-09-09 01:04:40	2026-09-09 01:04:40	\N
29	\N	fe6eda2f-83c2-499c-b586-db56b6bc9083	2026-09-09 01:04:43	2026-09-09 01:04:43	\N
30	\N	5b60f4ad-6161-470a-9739-778e9f24a065	2026-09-09 01:04:47	2026-09-09 01:04:47	\N
31	\N	34b563de-fcde-4bf1-86fe-b36a6e4606cc	2026-09-09 01:04:51	2026-09-09 01:04:51	\N
32	\N	ba548a64-3866-4019-b211-656830790d2d	2026-09-09 01:04:54	2026-09-09 01:04:54	\N
33	\N	e229053a-d9ea-43b7-99ae-34b48c9253da	2026-09-09 01:08:31	2026-09-09 01:08:31	\N
34	\N	4d469fa1-ad71-429e-b923-b84d2126b53e	2026-09-09 02:30:37	2026-09-09 02:30:37	\N
35	\N	990209bc-8701-468c-92f6-b78c5c97d794	2026-09-09 02:31:09	2026-09-09 02:31:09	\N
36	\N	ae30e995-81b0-4e77-b331-0146ce2e0406	2026-09-09 03:41:05	2026-09-09 03:41:05	\N
37	\N	4ab11fd9-4493-4873-8b2c-27ab5bb71071	2026-09-09 03:50:22	2026-09-09 03:50:22	\N
38	\N	fb84f89e-25e2-4013-abdd-a7f08311c04f	2026-09-09 03:50:42	2026-09-09 03:50:42	\N
39	\N	039693af-88c6-4c18-934a-d27a4b93a649	2026-09-09 03:59:34	2026-09-09 03:59:34	\N
40	\N	44e68c09-e3a8-43af-9e85-bf562eb1d4cf	2026-09-09 04:00:07	2026-09-09 04:00:07	\N
41	\N	726f65f0-b989-49ed-80ce-06fec4490e70	2026-09-09 04:04:28	2026-09-09 04:04:28	\N
42	\N	e1fdc025-e294-466c-9edb-ea1b671fc5b1	2026-09-09 04:09:47	2026-09-09 04:09:47	\N
43	\N	5e0fe915-bbad-49e4-9611-c6bf6b8b701a	2026-09-09 04:10:23	2026-09-09 04:10:23	\N
44	\N	24f277fa-c345-4d71-92d5-9dd817ae1d7b	2026-09-09 04:25:35	2026-09-09 04:25:35	\N
45	\N	d161588b-80cf-4ba4-8275-74038c1d61d4	2026-09-09 04:26:15	2026-09-09 04:26:15	\N
46	\N	45f1394b-ec1d-4531-b084-61f081a3ee41	2026-09-09 04:26:23	2026-09-09 04:26:23	\N
47	\N	e1ef283e-fc71-46e1-9e1e-b86db29d639a	2026-09-09 04:27:45	2026-09-09 04:27:45	\N
48	\N	39facddf-915f-4d99-9bb4-cc709975d7d7	2026-09-09 04:51:02	2026-09-09 04:51:02	\N
49	\N	7f3f6057-ab39-456a-b03c-e1d0943a4690	2026-09-09 04:51:06	2026-09-09 04:51:06	\N
50	\N	08921191-7b4b-47df-aeb3-0794c9455c13	2026-09-09 04:57:00	2026-09-09 04:57:00	\N
51	\N	cf77572e-3601-45f7-82ae-76e0712b723b	2026-09-09 04:58:25	2026-09-09 04:58:25	\N
52	\N	627e77ba-0513-4329-b62e-53fdd8e90b84	2026-09-09 05:00:50	2026-09-09 05:00:50	\N
53	\N	885d9c5a-27dc-477c-9d07-343107df63a2	2026-09-10 04:24:18	2026-09-10 04:24:18	\N
54	\N	d00879d6-f374-4eb6-87cf-be458879cdff	2026-09-10 04:24:41	2026-09-10 04:24:41	\N
55	\N	6870acd9-8502-4400-8a80-fa6ee9e88cb3	2026-09-10 04:25:27	2026-09-10 04:25:27	\N
56	\N	aff0ceab-64e3-48c9-9400-fdbd80adfb91	2026-09-10 04:28:04	2026-09-10 04:28:04	\N
57	\N	f295c584-a1f2-4505-b96a-8f83191ec12a	2026-09-10 04:28:24	2026-09-10 04:28:24	\N
58	\N	34ef9792-09da-409f-b4c3-16f0833478d1	2026-09-10 04:29:08	2026-09-10 04:29:08	\N
59	\N	870f8f3f-c1f9-4873-817b-94d3e7c77a86	2026-09-10 04:33:57	2026-09-10 04:33:57	\N
60	\N	95557eaf-6f37-4c92-b1fc-b90425f40fc5	2026-09-10 04:34:21	2026-09-10 04:34:21	\N
61	\N	31202cb2-6132-4e09-84ab-be79b2570100	2026-09-10 04:35:05	2026-09-10 04:35:05	\N
62	\N	560771ca-2c09-49c1-aa2b-0c8202d79354	2026-09-10 04:49:35	2026-09-10 04:49:35	\N
63	\N	d1195b76-1ccf-4b42-9748-c47d6e775d88	2026-09-10 04:51:10	2026-09-10 04:51:10	\N
64	\N	ebc5f54a-3dbe-4dea-bf94-30be7c8cc504	2026-09-10 04:51:53	2026-09-10 04:51:53	\N
65	\N	649df704-6aaf-4562-8692-56406acad6d4	2026-09-10 04:58:39	2026-09-10 04:58:39	\N
66	\N	41044935-287f-4337-a168-72ba621a979b	2026-09-10 04:59:08	2026-09-10 04:59:08	\N
67	\N	5775209d-8729-494d-ace4-bb8f2dd30938	2026-09-10 04:59:57	2026-09-10 04:59:57	\N
68	\N	2259afa9-4f31-4849-b3b5-5d27dc5d4e62	2026-09-10 05:00:26	2026-09-10 05:00:26	\N
69	\N	5276ead4-a57d-4551-9324-f116c1b2939a	2026-09-10 05:00:51	2026-09-10 05:00:51	\N
70	\N	ec67ec43-2f17-467a-a15b-032165ad489c	2026-09-10 05:01:38	2026-09-10 05:01:38	\N
71	\N	d4e3384c-c00f-415c-a6c5-28df7f00ea0f	2026-09-10 05:02:42	2026-09-10 05:02:42	\N
72	\N	531a6e1e-4e05-4a33-9d5e-d2e3fb8a3d98	2026-09-10 05:03:10	2026-09-10 05:03:10	\N
73	\N	7d7ea26f-5151-4938-9c14-feab9b39167e	2026-09-10 05:04:09	2026-09-10 05:04:09	\N
74	\N	b4a53eca-29cb-4cc1-a575-c9509695c63f	2026-09-10 05:04:55	2026-09-10 05:04:55	\N
75	\N	9ef619b2-f3b0-4130-9be3-21cbefe60dc5	2026-09-10 05:04:59	2026-09-10 05:04:59	\N
76	\N	c2c6ac42-328f-4357-b478-8be94a1a2585	2026-09-10 05:05:13	2026-09-10 05:05:13	\N
77	\N	6080d7e0-93ad-42a6-bbc7-e59d74d5c4e7	2026-09-10 05:05:51	2026-09-10 05:05:51	\N
78	\N	98370ec0-470a-41be-b68e-b0567c50a698	2026-09-10 05:05:55	2026-09-10 05:05:55	\N
79	\N	404b6920-1242-4993-a00b-99be2f1238b2	2026-09-10 05:05:59	2026-09-10 05:05:59	\N
80	\N	00fbe713-cba9-4cc6-b2bd-0b40b402e590	2026-09-10 05:06:30	2026-09-10 05:06:30	\N
81	\N	635444fb-2795-4be1-adf2-73054f7708d0	2026-09-10 05:07:02	2026-09-10 05:07:02	\N
82	\N	2170261f-b703-4f57-880a-c77bcbff5cb3	2026-09-10 05:07:52	2026-09-10 05:07:52	\N
83	\N	2ecab2a1-a2c3-4dc4-956a-6a4852962cde	2026-09-10 05:08:26	2026-09-10 05:08:26	\N
84	\N	8c8a18c1-f450-47c0-9b34-bcf0e282f527	2026-09-10 05:08:53	2026-09-10 05:08:53	\N
85	\N	2a0cad01-ac0c-4781-884a-248b50b41a10	2026-09-10 05:09:42	2026-09-10 05:09:42	\N
86	\N	4d26e9d9-953c-4c1a-9f2b-0e699326de4e	2026-09-10 05:10:08	2026-09-10 05:10:08	\N
87	\N	98ab3e79-5840-49a3-9fcb-ae77f5ad1604	2026-09-10 05:10:38	2026-09-10 05:10:38	\N
88	\N	9807b147-09fc-4db2-862f-2cd48815fe6b	2026-09-10 05:11:31	2026-09-10 05:11:31	\N
89	\N	f50d8a4a-6fff-4e56-874c-674f2ba8c952	2026-09-10 05:20:22	2026-09-10 05:20:22	\N
90	\N	ce491e6e-bc78-4843-81aa-cf0fc1d9533b	2026-09-10 05:20:55	2026-09-10 05:20:55	\N
91	\N	03c96d5b-9b18-40b3-8166-701879ba94c7	2026-09-10 05:21:45	2026-09-10 05:21:45	\N
92	\N	e2bdec29-89c0-44a9-b17e-6a226ac566a6	2026-09-10 05:32:44	2026-09-10 05:32:44	\N
93	\N	5a88d335-2d62-48d8-ab36-75308252bb6e	2026-09-10 05:33:05	2026-09-10 05:33:05	\N
94	\N	2c06ad90-bc57-4516-9672-da57e295b291	2026-09-10 05:33:47	2026-09-10 05:33:47	\N
95	\N	946d84a6-9f8d-4dd6-aa20-1ff5fca87c97	2026-09-10 05:47:37	2026-09-10 05:47:37	\N
96	\N	5980d1af-2ed8-46a2-b6ad-c954783eb784	2026-09-10 05:53:25	2026-09-10 05:53:25	\N
97	\N	3063c500-d1cc-4bbe-8cb4-bb85d7f3a4e0	2026-09-10 05:53:53	2026-09-10 05:53:53	\N
98	\N	9a7375d1-7317-4126-94b7-0155def7ae66	2026-09-10 05:54:34	2026-09-10 05:54:34	\N
99	\N	8d09aff1-5d23-4875-88c5-0cfde0dd9024	2026-09-10 05:55:01	2026-09-10 05:55:01	\N
100	\N	762e86dc-6c30-4599-983a-3866a82e79ec	2026-09-10 05:55:24	2026-09-10 05:55:24	\N
101	\N	46187e9b-5129-4ad1-9871-8a1f09f0374c	2026-09-10 05:56:10	2026-09-10 05:56:10	\N
102	\N	df5f06d2-38c3-4b17-84e1-7a79103b7787	2026-09-10 06:02:33	2026-09-10 06:02:33	\N
103	\N	3731d492-d1eb-481a-9bca-de6bf66fa221	2026-09-10 06:02:56	2026-09-10 06:02:56	\N
104	\N	647d56f3-7e14-4071-b8b0-a73078091ae0	2026-09-10 06:03:38	2026-09-10 06:03:38	\N
105	\N	c8a9eb4a-3ea7-4a43-9024-173732b138ce	2026-09-10 06:04:07	2026-09-10 06:04:07	\N
106	\N	d59df931-b016-46d7-8fa5-ddd0385a70d8	2026-09-10 06:04:32	2026-09-10 06:04:32	\N
107	\N	ede612c5-f492-45db-9852-410fc80531e2	2026-09-10 06:05:12	2026-09-10 06:05:12	\N
108	\N	fd72f76a-cf4e-4d4f-9f42-cfcacb83c4c9	2026-09-10 06:05:33	2026-09-10 06:05:33	\N
109	\N	1fc9b24b-8298-47c2-9c9e-504e5f6742d0	2026-09-10 06:05:56	2026-09-10 06:05:56	\N
110	\N	eafccc7c-26c3-4359-83ab-e2f09595e3de	2026-09-10 06:06:35	2026-09-10 06:06:35	\N
111	\N	7bea0bd0-88bc-4789-9efb-776839691880	2026-09-10 07:22:24	2026-09-10 07:22:24	\N
112	\N	5f996f4d-eb50-4bbf-954b-f2482e2d1225	2026-09-11 00:39:32	2026-09-11 00:39:32	\N
113	\N	5f764827-f623-4647-9231-1eb58423ecb4	2026-09-12 11:17:51	2026-09-12 11:17:51	\N
114	\N	3843c4d8-8998-4409-a8b4-0c036f09cbb7	2026-09-13 05:14:01	2026-09-13 05:14:01	\N
\.


--
-- Data for Name: coupons; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.coupons (id, code, type, value, first_order_per_account, usage_limit, usage_count, expires_at, created_at, updated_at) FROM stdin;
1	DEV15	percent	15	f	\N	0	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
2	PREVIEW15	percent	15	f	\N	0	2026-10-04 06:01:26	2026-09-04 06:01:26	2026-09-04 06:01:26
\.


--
-- Data for Name: crawl_runs; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.crawl_runs (id, status, pages, links, broken, orphans, max_depth, failure, started_at, finished_at) FROM stdin;
\.


--
-- Data for Name: crawled_pages; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.crawled_pages (id, run_id, path, status, depth, inbound, outbound, title, canonical, robots, redirect_to, discovered_from, crawled_at) FROM stdin;
\.


--
-- Data for Name: curriculum_aliases; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.curriculum_aliases (id, entity_type, entity_id, alias, normalized_alias, created_at, updated_at) FROM stdin;
1	stage	1	ابتدائي	ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
2	stage	1	المرحلة الابتدائية	المرحله الابتداييه	2026-09-04 06:01:24	2026-09-04 06:01:24
3	stage	1	الابتدائية	الابتداييه	2026-09-04 06:01:24	2026-09-04 06:01:24
4	stage	1	ابتدائية	ابتداييه	2026-09-04 06:01:24	2026-09-04 06:01:24
5	stage	2	متوسط	متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
6	stage	2	المرحلة المتوسطة	المرحله المتوسطه	2026-09-04 06:01:24	2026-09-04 06:01:24
7	stage	2	المتوسطة	المتوسطه	2026-09-04 06:01:24	2026-09-04 06:01:24
8	stage	2	متوسطة	متوسطه	2026-09-04 06:01:24	2026-09-04 06:01:24
9	stage	3	ثانوي	ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
10	stage	3	المرحلة الثانوية	المرحله الثانويه	2026-09-04 06:01:24	2026-09-04 06:01:24
11	stage	3	الثانوية	الثانويه	2026-09-04 06:01:24	2026-09-04 06:01:24
12	stage	3	ثانوية	ثانويه	2026-09-04 06:01:24	2026-09-04 06:01:24
13	grade	1	الصف الأول الابتدائي	الصف الاول الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
14	grade	1	أول ابتدائي	اول ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
15	grade	1	الصف أول ابتدائي	الصف اول ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
16	grade	1	أول الابتدائي	اول الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
17	grade	1	الصف أول الابتدائي	الصف اول الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
18	grade	1	الأول ابتدائي	الاول ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
19	grade	1	الصف الأول ابتدائي	الصف الاول ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
20	grade	1	الأول الابتدائي	الاول الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
21	grade	1	1 ابتدائي	1 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
22	grade	1	الصف 1 ابتدائي	الصف 1 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
23	grade	1	1 الابتدائي	1 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
24	grade	1	الصف 1 الابتدائي	الصف 1 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
25	grade	2	الصف الثاني الابتدائي	الصف الثاني الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
26	grade	2	ثاني ابتدائي	ثاني ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
27	grade	2	الصف ثاني ابتدائي	الصف ثاني ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
28	grade	2	ثاني الابتدائي	ثاني الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
29	grade	2	الصف ثاني الابتدائي	الصف ثاني الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
30	grade	2	الثاني ابتدائي	الثاني ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
31	grade	2	الصف الثاني ابتدائي	الصف الثاني ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
32	grade	2	الثاني الابتدائي	الثاني الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
33	grade	2	2 ابتدائي	2 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
34	grade	2	الصف 2 ابتدائي	الصف 2 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
35	grade	2	2 الابتدائي	2 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
36	grade	2	الصف 2 الابتدائي	الصف 2 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
37	grade	3	الصف الثالث الابتدائي	الصف الثالث الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
38	grade	3	ثالث ابتدائي	ثالث ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
39	grade	3	الصف ثالث ابتدائي	الصف ثالث ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
40	grade	3	ثالث الابتدائي	ثالث الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
41	grade	3	الصف ثالث الابتدائي	الصف ثالث الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
42	grade	3	الثالث ابتدائي	الثالث ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
43	grade	3	الصف الثالث ابتدائي	الصف الثالث ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
44	grade	3	الثالث الابتدائي	الثالث الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
45	grade	3	3 ابتدائي	3 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
46	grade	3	الصف 3 ابتدائي	الصف 3 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
47	grade	3	3 الابتدائي	3 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
48	grade	3	الصف 3 الابتدائي	الصف 3 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
49	grade	4	الصف الرابع الابتدائي	الصف الرابع الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
50	grade	4	رابع ابتدائي	رابع ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
51	grade	4	الصف رابع ابتدائي	الصف رابع ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
52	grade	4	رابع الابتدائي	رابع الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
53	grade	4	الصف رابع الابتدائي	الصف رابع الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
54	grade	4	الرابع ابتدائي	الرابع ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
55	grade	4	الصف الرابع ابتدائي	الصف الرابع ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
56	grade	4	الرابع الابتدائي	الرابع الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
57	grade	4	4 ابتدائي	4 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
58	grade	4	الصف 4 ابتدائي	الصف 4 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
59	grade	4	4 الابتدائي	4 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
60	grade	4	الصف 4 الابتدائي	الصف 4 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
61	grade	5	الصف الخامس الابتدائي	الصف الخامس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
62	grade	5	خامس ابتدائي	خامس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
63	grade	5	الصف خامس ابتدائي	الصف خامس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
64	grade	5	خامس الابتدائي	خامس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
65	grade	5	الصف خامس الابتدائي	الصف خامس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
66	grade	5	الخامس ابتدائي	الخامس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
67	grade	5	الصف الخامس ابتدائي	الصف الخامس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
68	grade	5	الخامس الابتدائي	الخامس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
69	grade	5	5 ابتدائي	5 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
70	grade	5	الصف 5 ابتدائي	الصف 5 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
71	grade	5	5 الابتدائي	5 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
72	grade	5	الصف 5 الابتدائي	الصف 5 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
73	grade	6	الصف السادس الابتدائي	الصف السادس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
74	grade	6	سادس ابتدائي	سادس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
75	grade	6	الصف سادس ابتدائي	الصف سادس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
76	grade	6	سادس الابتدائي	سادس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
77	grade	6	الصف سادس الابتدائي	الصف سادس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
78	grade	6	السادس ابتدائي	السادس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
79	grade	6	الصف السادس ابتدائي	الصف السادس ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
80	grade	6	السادس الابتدائي	السادس الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
81	grade	6	6 ابتدائي	6 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
82	grade	6	الصف 6 ابتدائي	الصف 6 ابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
83	grade	6	6 الابتدائي	6 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
84	grade	6	الصف 6 الابتدائي	الصف 6 الابتدايي	2026-09-04 06:01:24	2026-09-04 06:01:24
85	grade	7	الصف الأول المتوسط	الصف الاول المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
86	grade	7	أول متوسط	اول متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
87	grade	7	الصف أول متوسط	الصف اول متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
88	grade	7	أول المتوسط	اول المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
89	grade	7	الصف أول المتوسط	الصف اول المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
90	grade	7	الأول متوسط	الاول متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
91	grade	7	الصف الأول متوسط	الصف الاول متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
92	grade	7	الأول المتوسط	الاول المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
93	grade	7	1 متوسط	1 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
94	grade	7	الصف 1 متوسط	الصف 1 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
95	grade	7	1 المتوسط	1 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
96	grade	7	الصف 1 المتوسط	الصف 1 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
97	grade	8	الصف الثاني المتوسط	الصف الثاني المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
98	grade	8	ثاني متوسط	ثاني متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
99	grade	8	الصف ثاني متوسط	الصف ثاني متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
100	grade	8	ثاني المتوسط	ثاني المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
101	grade	8	الصف ثاني المتوسط	الصف ثاني المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
102	grade	8	الثاني متوسط	الثاني متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
103	grade	8	الصف الثاني متوسط	الصف الثاني متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
104	grade	8	الثاني المتوسط	الثاني المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
105	grade	8	2 متوسط	2 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
106	grade	8	الصف 2 متوسط	الصف 2 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
107	grade	8	2 المتوسط	2 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
108	grade	8	الصف 2 المتوسط	الصف 2 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
109	grade	9	الصف الثالث المتوسط	الصف الثالث المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
110	grade	9	ثالث متوسط	ثالث متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
111	grade	9	الصف ثالث متوسط	الصف ثالث متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
112	grade	9	ثالث المتوسط	ثالث المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
113	grade	9	الصف ثالث المتوسط	الصف ثالث المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
114	grade	9	الثالث متوسط	الثالث متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
115	grade	9	الصف الثالث متوسط	الصف الثالث متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
116	grade	9	الثالث المتوسط	الثالث المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
117	grade	9	3 متوسط	3 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
118	grade	9	الصف 3 متوسط	الصف 3 متوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
119	grade	9	3 المتوسط	3 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
120	grade	9	الصف 3 المتوسط	الصف 3 المتوسط	2026-09-04 06:01:24	2026-09-04 06:01:24
121	grade	10	الصف الأول الثانوي	الصف الاول الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
122	grade	10	أول ثانوي	اول ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
123	grade	10	الصف أول ثانوي	الصف اول ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
124	grade	10	أول الثانوي	اول الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
125	grade	10	الصف أول الثانوي	الصف اول الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
126	grade	10	الأول ثانوي	الاول ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
127	grade	10	الصف الأول ثانوي	الصف الاول ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
128	grade	10	الأول الثانوي	الاول الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
129	grade	10	1 ثانوي	1 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
130	grade	10	الصف 1 ثانوي	الصف 1 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
131	grade	10	1 الثانوي	1 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
132	grade	10	الصف 1 الثانوي	الصف 1 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
133	grade	11	الصف الثاني الثانوي	الصف الثاني الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
134	grade	11	ثاني ثانوي	ثاني ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
135	grade	11	الصف ثاني ثانوي	الصف ثاني ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
136	grade	11	ثاني الثانوي	ثاني الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
137	grade	11	الصف ثاني الثانوي	الصف ثاني الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
138	grade	11	الثاني ثانوي	الثاني ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
139	grade	11	الصف الثاني ثانوي	الصف الثاني ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
140	grade	11	الثاني الثانوي	الثاني الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
141	grade	11	2 ثانوي	2 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
142	grade	11	الصف 2 ثانوي	الصف 2 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
143	grade	11	2 الثانوي	2 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
144	grade	11	الصف 2 الثانوي	الصف 2 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
145	grade	12	الصف الثالث الثانوي	الصف الثالث الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
146	grade	12	ثالث ثانوي	ثالث ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
147	grade	12	الصف ثالث ثانوي	الصف ثالث ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
148	grade	12	ثالث الثانوي	ثالث الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
149	grade	12	الصف ثالث الثانوي	الصف ثالث الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
150	grade	12	الثالث ثانوي	الثالث ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
151	grade	12	الصف الثالث ثانوي	الصف الثالث ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
152	grade	12	الثالث الثانوي	الثالث الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
153	grade	12	3 ثانوي	3 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
154	grade	12	الصف 3 ثانوي	الصف 3 ثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
155	grade	12	3 الثانوي	3 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
156	grade	12	الصف 3 الثانوي	الصف 3 الثانوي	2026-09-04 06:01:24	2026-09-04 06:01:24
157	subject	1	لغتي	لغتي	2026-09-04 06:01:24	2026-09-04 06:01:24
158	subject	1	لغتي الجميلة	لغتي الجميله	2026-09-04 06:01:24	2026-09-04 06:01:24
159	subject	1	لغتي الخالدة	لغتي الخالده	2026-09-04 06:01:24	2026-09-04 06:01:24
160	subject	2	اللغة العربية	اللغه العربيه	2026-09-04 06:01:24	2026-09-04 06:01:24
161	subject	2	عربي	عربي	2026-09-04 06:01:24	2026-09-04 06:01:24
162	subject	2	النحو	النحو	2026-09-04 06:01:24	2026-09-04 06:01:24
163	subject	2	الأدب	الادب	2026-09-04 06:01:24	2026-09-04 06:01:24
164	subject	2	البلاغة	البلاغه	2026-09-04 06:01:24	2026-09-04 06:01:24
165	subject	3	الكفايات اللغوية	الكفايات اللغويه	2026-09-04 06:01:24	2026-09-04 06:01:24
166	subject	3	كفايات لغوية	كفايات لغويه	2026-09-04 06:01:24	2026-09-04 06:01:24
167	subject	3	كفايات	كفايات	2026-09-04 06:01:24	2026-09-04 06:01:24
168	subject	4	الرياضيات	الرياضيات	2026-09-04 06:01:24	2026-09-04 06:01:24
169	subject	4	رياضيات	رياضيات	2026-09-04 06:01:24	2026-09-04 06:01:24
170	subject	4	حساب	حساب	2026-09-04 06:01:24	2026-09-04 06:01:24
171	subject	5	العلوم	العلوم	2026-09-04 06:01:24	2026-09-04 06:01:24
172	subject	5	علوم	علوم	2026-09-04 06:01:24	2026-09-04 06:01:24
173	subject	6	الفيزياء	الفيزياء	2026-09-04 06:01:24	2026-09-04 06:01:24
174	subject	6	فيزياء	فيزياء	2026-09-04 06:01:24	2026-09-04 06:01:24
175	subject	7	الكيمياء	الكيمياء	2026-09-04 06:01:24	2026-09-04 06:01:24
176	subject	7	كيمياء	كيمياء	2026-09-04 06:01:24	2026-09-04 06:01:24
177	subject	8	الأحياء	الاحياء	2026-09-04 06:01:24	2026-09-04 06:01:24
178	subject	8	أحياء	احياء	2026-09-04 06:01:24	2026-09-04 06:01:24
179	subject	9	الدراسات الإسلامية	الدراسات الاسلاميه	2026-09-04 06:01:24	2026-09-04 06:01:24
180	subject	9	إسلامية	اسلاميه	2026-09-04 06:01:24	2026-09-04 06:01:24
181	subject	9	تربية إسلامية	تربيه اسلاميه	2026-09-04 06:01:24	2026-09-04 06:01:24
182	subject	9	التوحيد	التوحيد	2026-09-04 06:01:24	2026-09-04 06:01:24
183	subject	9	الفقه	الفقه	2026-09-04 06:01:24	2026-09-04 06:01:24
184	subject	9	التفسير	التفسير	2026-09-04 06:01:24	2026-09-04 06:01:24
185	subject	9	الحديث	الحديث	2026-09-04 06:01:24	2026-09-04 06:01:24
186	subject	10	القرآن الكريم	القران الكريم	2026-09-04 06:01:24	2026-09-04 06:01:24
187	subject	10	قرآن	قران	2026-09-04 06:01:24	2026-09-04 06:01:24
188	subject	10	تلاوة	تلاوه	2026-09-04 06:01:24	2026-09-04 06:01:24
189	subject	10	تجويد	تجويد	2026-09-04 06:01:24	2026-09-04 06:01:24
190	subject	11	الدراسات الاجتماعية	الدراسات الاجتماعيه	2026-09-04 06:01:24	2026-09-04 06:01:24
191	subject	11	اجتماعيات	اجتماعيات	2026-09-04 06:01:24	2026-09-04 06:01:24
192	subject	11	التاريخ	التاريخ	2026-09-04 06:01:24	2026-09-04 06:01:24
193	subject	11	الجغرافيا	الجغرافيا	2026-09-04 06:01:24	2026-09-04 06:01:24
194	subject	11	الوطنية	الوطنيه	2026-09-04 06:01:24	2026-09-04 06:01:24
195	subject	12	اللغة الإنجليزية	اللغه الانجليزيه	2026-09-04 06:01:24	2026-09-04 06:01:24
196	subject	12	إنجليزي	انجليزي	2026-09-04 06:01:24	2026-09-04 06:01:24
197	subject	12	english	english	2026-09-04 06:01:24	2026-09-04 06:01:24
198	subject	13	المهارات الرقمية	المهارات الرقميه	2026-09-04 06:01:24	2026-09-04 06:01:24
199	subject	13	مهارات رقمية	مهارات رقميه	2026-09-04 06:01:24	2026-09-04 06:01:24
200	subject	13	الحاسب	الحاسب	2026-09-04 06:01:24	2026-09-04 06:01:24
201	subject	13	الحاسب الآلي	الحاسب الالي	2026-09-04 06:01:24	2026-09-04 06:01:24
202	subject	13	حاسوب	حاسوب	2026-09-04 06:01:24	2026-09-04 06:01:24
203	subject	14	المهارات الحياتية	المهارات الحياتيه	2026-09-04 06:01:24	2026-09-04 06:01:24
204	subject	14	مهارات حياتية	مهارات حياتيه	2026-09-04 06:01:24	2026-09-04 06:01:24
205	subject	14	الأسرية	الاسريه	2026-09-04 06:01:24	2026-09-04 06:01:24
206	subject	14	التربية الأسرية	التربيه الاسريه	2026-09-04 06:01:24	2026-09-04 06:01:24
207	subject	15	التفكير الناقد	التفكير الناقد	2026-09-04 06:01:24	2026-09-04 06:01:24
208	subject	15	تفكير ناقد	تفكير ناقد	2026-09-04 06:01:24	2026-09-04 06:01:24
209	subject	15	التفكير	التفكير	2026-09-04 06:01:24	2026-09-04 06:01:24
210	subject	16	التربية الفنية	التربيه الفنيه	2026-09-04 06:01:24	2026-09-04 06:01:24
211	subject	16	فنية	فنيه	2026-09-04 06:01:24	2026-09-04 06:01:24
212	subject	16	الفنون	الفنون	2026-09-04 06:01:24	2026-09-04 06:01:24
213	subject	17	التربية البدنية	التربيه البدنيه	2026-09-04 06:01:24	2026-09-04 06:01:24
214	subject	17	بدنية	بدنيه	2026-09-04 06:01:24	2026-09-04 06:01:24
215	subject	17	رياضة	رياضه	2026-09-04 06:01:24	2026-09-04 06:01:24
216	subject	18	علم البيئة	علم البييه	2026-09-04 06:01:24	2026-09-04 06:01:24
217	subject	18	بيئة	بييه	2026-09-04 06:01:24	2026-09-04 06:01:24
218	semester	1	الفصل الدراسي الأول	الفصل الدراسي الاول	2026-09-04 06:01:24	2026-09-04 06:01:24
219	semester	1	الفصل الأول	الفصل الاول	2026-09-04 06:01:24	2026-09-04 06:01:24
220	semester	1	ف1	ف1	2026-09-04 06:01:24	2026-09-04 06:01:24
221	semester	1	الترم الأول	الترم الاول	2026-09-04 06:01:24	2026-09-04 06:01:24
222	semester	2	الفصل الدراسي الثاني	الفصل الدراسي الثاني	2026-09-04 06:01:24	2026-09-04 06:01:24
223	semester	2	الفصل الثاني	الفصل الثاني	2026-09-04 06:01:24	2026-09-04 06:01:24
224	semester	2	ف2	ف2	2026-09-04 06:01:24	2026-09-04 06:01:24
225	semester	2	الترم الثاني	الترم الثاني	2026-09-04 06:01:24	2026-09-04 06:01:24
226	semester	3	الفصل الدراسي الثالث	الفصل الدراسي الثالث	2026-09-04 06:01:24	2026-09-04 06:01:24
227	semester	3	الفصل الثالث	الفصل الثالث	2026-09-04 06:01:24	2026-09-04 06:01:24
228	semester	3	ف3	ف3	2026-09-04 06:01:24	2026-09-04 06:01:24
229	semester	3	الترم الثالث	الترم الثالث	2026-09-04 06:01:24	2026-09-04 06:01:24
230	kind	1	تحضير	تحضير	2026-09-04 06:01:24	2026-09-04 06:01:24
231	kind	1	تحاضير	تحاضير	2026-09-04 06:01:24	2026-09-04 06:01:24
232	kind	1	تحضير درس	تحضير درس	2026-09-04 06:01:24	2026-09-04 06:01:24
233	kind	1	دفتر تحضير	دفتر تحضير	2026-09-04 06:01:24	2026-09-04 06:01:24
234	kind	1	خطة درس	خطه درس	2026-09-04 06:01:24	2026-09-04 06:01:24
235	kind	2	عرض بوربوينت	عرض بوربوينت	2026-09-04 06:01:24	2026-09-04 06:01:24
236	kind	2	بوربوينت	بوربوينت	2026-09-04 06:01:24	2026-09-04 06:01:24
237	kind	2	عروض	عروض	2026-09-04 06:01:24	2026-09-04 06:01:24
238	kind	2	عرض تقديمي	عرض تقديمي	2026-09-04 06:01:24	2026-09-04 06:01:24
239	kind	2	powerpoint	powerpoint	2026-09-04 06:01:24	2026-09-04 06:01:24
240	kind	2	ppt	ppt	2026-09-04 06:01:24	2026-09-04 06:01:24
241	kind	3	أوراق عمل	اوراق عمل	2026-09-04 06:01:24	2026-09-04 06:01:24
242	kind	3	ورق عمل	ورق عمل	2026-09-04 06:01:24	2026-09-04 06:01:24
243	kind	3	ورقة عمل	ورقه عمل	2026-09-04 06:01:24	2026-09-04 06:01:24
244	kind	3	أنشطة	انشطه	2026-09-04 06:01:24	2026-09-04 06:01:24
245	kind	3	تدريبات	تدريبات	2026-09-04 06:01:24	2026-09-04 06:01:24
246	kind	4	اختبارات	اختبارات	2026-09-04 06:01:24	2026-09-04 06:01:24
247	kind	4	اختبار	اختبار	2026-09-04 06:01:24	2026-09-04 06:01:24
248	kind	4	بنك أسئلة	بنك اسيله	2026-09-04 06:01:24	2026-09-04 06:01:24
249	kind	4	أسئلة	اسيله	2026-09-04 06:01:24	2026-09-04 06:01:24
250	kind	4	نماذج اختبار	نماذج اختبار	2026-09-04 06:01:24	2026-09-04 06:01:24
251	kind	5	توزيع	توزيع	2026-09-04 06:01:24	2026-09-04 06:01:24
252	kind	5	توزيع المنهج	توزيع المنهج	2026-09-04 06:01:24	2026-09-04 06:01:24
253	kind	5	خطة توزيع	خطه توزيع	2026-09-04 06:01:24	2026-09-04 06:01:24
254	kind	6	وسائل تعليمية	وسايل تعليميه	2026-09-04 06:01:24	2026-09-04 06:01:24
255	kind	6	وسائل	وسايل	2026-09-04 06:01:24	2026-09-04 06:01:24
256	kind	6	سبورة	سبوره	2026-09-04 06:01:24	2026-09-04 06:01:24
257	kind	6	لوحات	لوحات	2026-09-04 06:01:24	2026-09-04 06:01:24
258	kind	7	حلول	حلول	2026-09-04 06:01:24	2026-09-04 06:01:24
259	kind	7	حل	حل	2026-09-04 06:01:24	2026-09-04 06:01:24
260	kind	7	الحل	الحل	2026-09-04 06:01:24	2026-09-04 06:01:24
261	kind	7	إجابات	اجابات	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: customer_sessions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.customer_sessions (id, customer_id, token_hash, expires_at, last_seen_at, user_agent, created_at, updated_at) FROM stdin;
1	15	ab2217c79e00b282bb5ce31e0a8db67d80de495aa782fa2f10f89c40c194e4a4	2026-10-09 02:15:16	2026-09-09 02:15:16	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-09 02:15:16	2026-09-09 02:15:16
4	17	380b4eb348d6b25b75a1ec7b3705d957b279fe13345c75d62e222fcbe4e45816	2026-10-09 02:15:48	2026-09-09 02:15:48	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-09 02:15:48	2026-09-09 02:15:48
6	19	f17d97a3e4abc645a64ef19ac7bee0a22f15ce2ef4bb98c97161141ae78dfbde	2026-10-09 02:17:01	2026-09-09 02:17:01	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-09 02:17:01	2026-09-09 02:17:01
8	21	8bfe305b624a8ea091c0b0f22e2b9ef58191fdf9249eb665d1c2210e354a3739	2026-10-09 02:17:19	2026-09-09 02:17:19	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-09 02:17:19	2026-09-09 02:17:19
9	33	128b69beeea04e8f2ce59081a27e0de1a5d5d3f6d6107b7a67cdd5cc4d453330	2026-10-09 04:56:42	2026-09-09 04:56:42	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-09 04:56:42	2026-09-09 04:56:42
11	36	19322ae431111941c7e5ba0e9acc08173eca29fec978da5d71d330e60c7b2538	2026-10-10 04:23:03	2026-09-10 04:23:03	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.12 Safari/537.36	2026-09-10 04:23:03	2026-09-10 04:23:03
\.


--
-- Data for Name: customers; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.customers (id, email, name, phone, locale, marketing_consent, created_at, updated_at) FROM stdin;
1	design-preview-reviewer-01@example.invalid	نورة العتيبي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
2	design-preview-reviewer-02@example.invalid	محمد الشهري	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
3	design-preview-reviewer-03@example.invalid	أمل القحطاني	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
4	design-preview-reviewer-04@example.invalid	سارة الدوسري	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
5	design-preview-reviewer-05@example.invalid	عبدالله الغامدي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
6	design-preview-reviewer-06@example.invalid	هند الزهراني	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
7	design-preview-reviewer-07@example.invalid	خالد الحربي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
8	design-preview-reviewer-08@example.invalid	ريم المطيري	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
9	design-preview-reviewer-09@example.invalid	فهد السبيعي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
10	design-preview-reviewer-10@example.invalid	لمياء العنزي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
11	design-preview-reviewer-11@example.invalid	ماجد البقمي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
12	design-preview-reviewer-12@example.invalid	شيماء الرشيدي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
13	design-preview-reviewer-13@example.invalid	يوسف الخالدي	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
14	design-preview-reviewer-14@example.invalid	دانة الشمري	\N	ar	f	2026-09-04 06:01:26	2026-09-04 06:01:26
15	e2e-1788909310333@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:15:16	2026-09-09 02:15:17
17	e2e-1788909345802@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:15:48	2026-09-09 02:15:49
19	e2e-1788909419629@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:17:01	2026-09-09 02:17:03
21	e2e-1788909436606@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:17:19	2026-09-09 02:17:20
23	ty-1788910237918@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:30:38	2026-09-09 02:30:38
24	ty-1788910270025@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 02:31:10	2026-09-09 02:31:10
25	pf-1788914465@example.com	اختبار فشل	512345678	ar	f	2026-09-09 03:41:05	2026-09-09 03:41:05
26	inv-1788915022@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 03:50:22	2026-09-09 03:50:22
27	ty-1788915042372@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 03:50:42	2026-09-09 03:50:42
28	rf-1788915574@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 03:59:34	2026-09-09 03:59:34
29	ty-1788915607758@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 04:00:07	2026-09-09 04:00:07
30	rm-1788915868@example.com	نورة العتيبي	512345678	ar	t	2026-09-09 04:04:28	2026-09-09 04:04:28
31	ty-1788917183419@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 04:26:23	2026-09-09 04:26:23
32	ty-1788918666196@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 04:51:06	2026-09-09 04:51:06
33	e2e-1788918999455@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 04:56:42	2026-09-09 04:56:43
35	ty-1788919105742@example.com	نورة العتيبي	512345678	ar	f	2026-09-09 04:58:25	2026-09-09 04:58:25
36	e2e-1789003380828@example.com	e2e-1789003380828	\N	ar	f	2026-09-10 04:23:03	2026-09-10 04:23:03
37	ty-1789003527149@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:25:27	2026-09-10 04:25:27
38	e2e-1789003624153@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:27:06	2026-09-10 04:27:07
39	e2e-1789003632594@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:27:14	2026-09-10 04:27:15
40	e2e-1789003639997@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:27:22	2026-09-10 04:27:23
41	e2e-1789003660077@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:27:42	2026-09-10 04:27:43
42	ty-1789003748151@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:29:08	2026-09-10 04:29:08
43	e2e-1789004015378@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:33:38	2026-09-10 04:33:39
44	ty-1789004105645@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:35:05	2026-09-10 04:35:05
45	e2e-1789004839857@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:47:22	2026-09-10 04:47:23
46	ty-1789005113487@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:51:53	2026-09-10 04:51:53
47	e2e-1789005500611@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:58:23	2026-09-10 04:58:24
48	ty-1789005598011@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 04:59:58	2026-09-10 04:59:58
49	e2e-1789005603181@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:00:05	2026-09-10 05:00:06
50	ty-1789005698745@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:01:38	2026-09-10 05:01:38
51	e2e-1789005741616@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:02:24	2026-09-10 05:02:25
52	ty-1789005849804@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:04:09	2026-09-10 05:04:09
53	e2e-1789005968892@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:06:11	2026-09-10 05:06:12
54	ty-1789006072468@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:07:52	2026-09-10 05:07:52
55	e2e-1789006079857@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:08:02	2026-09-10 05:08:03
56	ty-1789006182300@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:09:42	2026-09-10 05:09:42
57	e2e-1789006187663@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:09:50	2026-09-10 05:09:51
58	ty-1789006291385@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:11:31	2026-09-10 05:11:31
59	e2e-1789006797869@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:20:00	2026-09-10 05:20:01
60	ty-1789006906085@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:21:46	2026-09-10 05:21:46
61	e2e-1789007542780@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:32:25	2026-09-10 05:32:26
62	ty-1789007627465@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:33:47	2026-09-10 05:33:47
63	ssr-1789008457@example.com	إثبات الهويّة	512345678	ar	f	2026-09-10 05:47:37	2026-09-10 05:47:37
64	e2e-1789008784193@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:53:07	2026-09-10 05:53:08
65	ty-1789008874712@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:54:34	2026-09-10 05:54:34
66	e2e-1789008881202@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:54:43	2026-09-10 05:54:44
67	ty-1789008970378@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 05:56:10	2026-09-10 05:56:10
68	e2e-1789009335141@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:02:17	2026-09-10 06:02:18
69	ty-1789009418127@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:03:38	2026-09-10 06:03:38
70	e2e-1789009425733@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:03:50	2026-09-10 06:03:51
71	ty-1789009512162@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:05:12	2026-09-10 06:05:12
72	e2e-1789009516066@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:05:18	2026-09-10 06:05:19
73	ty-1789009595370@example.com	نورة العتيبي	512345678	ar	f	2026-09-10 06:06:35	2026-09-10 06:06:35
74	noura@example.com	نورة العتيبي	512345678	ar	f	2026-09-11 00:39:33	2026-09-11 00:39:33
\.


--
-- Data for Name: design_component_versions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.design_component_versions (id, component_id, version, settings, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: design_components; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.design_components (id, component_key, component_type, variant, draft_settings, published_settings, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
2	tah/collection.0101	collection	\N	{"heading":"","source":"best","limit":6,"sort":"sales","skipOOS":{"oos":true,"empty":true},"manualIds":[],"parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"layout":"grid","cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","hSize":11,"hWeight":800,"headingLevel":"h2","ratio":"square","fit":"cover","hover":"lift","spotlight":false,"motion":5,"entrance":"up","hide":{"desk":false,"tab":false,"mob":false},"cond":"always","from":null,"to":null,"emptyMsg":"","errorMsg":"","crawl":{"a":true,"alt":true},"anchor":"","cls":""}	\N	1	\N	\N	\N	2026-09-04 09:28:11	2026-09-04 09:28:11
4	tah/typeahead.0402	collection	\N	{"heading":"","source":"best","limit":6,"sort":"sales","skipOOS":{"oos":true,"empty":true},"manualIds":[],"parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"layout":"grid","cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","hSize":11,"hWeight":800,"headingLevel":"h2","ratio":"square","fit":"cover","hover":"lift","spotlight":false,"motion":5,"entrance":"up","hide":{"desk":false,"tab":false,"mob":false},"cond":"always","from":null,"to":null,"emptyMsg":"","errorMsg":"","crawl":{"a":true,"alt":true},"anchor":"","cls":""}	\N	1	\N	\N	\N	2026-09-04 14:29:28	2026-09-04 14:29:28
5	tah/collection.0106	collection	\N	{"heading":"","source":"best","limit":6,"sort":"sales","skipOOS":{"oos":true,"empty":true},"manualIds":[],"parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"layout":"grid","cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","hSize":11,"hWeight":800,"headingLevel":"h2","ratio":"square","fit":"cover","hover":"lift","spotlight":false,"motion":5,"entrance":"up","hide":{"desk":false,"tab":false,"mob":false},"cond":"always","from":null,"to":null,"emptyMsg":"","errorMsg":"","crawl":{"a":true,"alt":true},"anchor":"","cls":""}	\N	1	\N	\N	\N	2026-09-04 15:06:54	2026-09-04 15:06:54
6	tah/collection.0107	collection	\N	{"heading":"","source":"best","limit":6,"sort":"sales","skipOOS":{"oos":true,"empty":true},"manualIds":[],"parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"layout":"grid","cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","hSize":11,"hWeight":800,"headingLevel":"h2","ratio":"square","fit":"cover","hover":"lift","spotlight":false,"motion":5,"entrance":"up","hide":{"desk":false,"tab":false,"mob":false},"cond":"always","from":null,"to":null,"emptyMsg":"","errorMsg":"","crawl":{"a":true,"alt":true},"anchor":"","cls":""}	\N	1	\N	\N	\N	2026-09-04 16:54:31	2026-09-04 16:54:31
\.


--
-- Data for Name: design_heroes; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.design_heroes (id, hero_key, name, target_type, target_ref, structure, variant, draft_settings, published_settings, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
1	homepage.main	بطل الصفحة الرئيسية	homepage	\N	search	\N	{"title":"\\u0627\\u0628\\u062d\\u062b \\u0639\\u0646 \\u062a\\u062d\\u0636\\u064a\\u0631\\u0643","subtitle":"\\u0627\\u0643\\u062a\\u0628 \\u0627\\u0644\\u0645\\u0627\\u062f\\u0629 \\u0623\\u0648 \\u0627\\u0644\\u0635\\u0641"}	{"title":"\\u0627\\u0628\\u062d\\u062b \\u0639\\u0646 \\u062a\\u062d\\u0636\\u064a\\u0631\\u0643","subtitle":"\\u0627\\u0643\\u062a\\u0628 \\u0627\\u0644\\u0645\\u0627\\u062f\\u0629 \\u0623\\u0648 \\u0627\\u0644\\u0635\\u0641"}	3	3	\N	2026-09-10 20:32:02	2026-09-04 16:57:09	2026-09-10 20:32:02
\.


--
-- Data for Name: download_events; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.download_events (id, entitlement_id, event_type, meta, created_at) FROM stdin;
1	5	revoked	{"reason":"\\u0645\\u0644\\u0627\\u062d\\u0638\\u0629 \\u062f\\u0627\\u062e\\u0644\\u064a\\u0629 \\u0644\\u0644\\u0645\\u0634\\u0631\\u0641","policy":"immediate"}	2026-09-09 00:59:35
\.


--
-- Data for Name: download_grants; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.download_grants (id, entitlement_id, token_hash, expires_at, used_at, ip, created_at, updated_at, asset_id) FROM stdin;
\.


--
-- Data for Name: entitlements; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.entitlements (id, customer_id, order_id, order_item_id, product_id, version_label, granted_at, expires_at, download_limit, download_count, status, revoked_at, revocation_reason, created_at, updated_at) FROM stdin;
1	23	124	124	10	\N	2026-09-09 02:30:42	\N	\N	0	ACTIVE	\N	\N	2026-09-09 02:30:42	2026-09-09 02:30:42
2	24	125	125	10	\N	2026-09-09 02:31:11	\N	\N	0	ACTIVE	\N	\N	2026-09-09 02:31:11	2026-09-09 02:31:11
3	26	127	127	10	\N	2026-09-09 03:50:22	\N	\N	0	ACTIVE	\N	\N	2026-09-09 03:50:22	2026-09-09 03:50:22
4	27	128	128	10	\N	2026-09-09 03:50:43	\N	\N	0	ACTIVE	\N	\N	2026-09-09 03:50:43	2026-09-09 03:50:43
5	28	129	129	10	\N	2026-09-09 03:59:34	\N	\N	0	REVOKED	2026-09-09 03:59:35	ملاحظة داخلية للمشرف	2026-09-09 03:59:34	2026-09-09 03:59:35
6	29	130	130	10	\N	2026-09-09 04:00:08	\N	\N	0	ACTIVE	\N	\N	2026-09-09 04:00:08	2026-09-09 04:00:08
7	31	132	132	10	\N	2026-09-09 04:26:24	\N	\N	0	ACTIVE	\N	\N	2026-09-09 04:26:24	2026-09-09 04:26:24
8	32	133	133	10	\N	2026-09-09 04:51:07	\N	\N	0	ACTIVE	\N	\N	2026-09-09 04:51:07	2026-09-09 04:51:07
9	35	134	134	10	\N	2026-09-09 04:58:27	\N	\N	0	ACTIVE	\N	\N	2026-09-09 04:58:27	2026-09-09 04:58:27
10	37	135	135	10	\N	2026-09-10 04:25:28	\N	\N	0	ACTIVE	\N	\N	2026-09-10 04:25:28	2026-09-10 04:25:28
11	42	136	136	10	\N	2026-09-10 04:29:09	\N	\N	0	ACTIVE	\N	\N	2026-09-10 04:29:09	2026-09-10 04:29:09
12	44	137	137	10	\N	2026-09-10 04:35:07	\N	\N	0	ACTIVE	\N	\N	2026-09-10 04:35:07	2026-09-10 04:35:07
13	46	138	138	10	\N	2026-09-10 04:51:54	\N	\N	0	ACTIVE	\N	\N	2026-09-10 04:51:54	2026-09-10 04:51:54
14	48	139	139	10	\N	2026-09-10 04:59:59	\N	\N	0	ACTIVE	\N	\N	2026-09-10 04:59:59	2026-09-10 04:59:59
15	50	140	140	10	\N	2026-09-10 05:01:42	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:01:42	2026-09-10 05:01:42
16	52	141	141	10	\N	2026-09-10 05:04:11	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:04:11	2026-09-10 05:04:11
17	54	142	142	10	\N	2026-09-10 05:07:54	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:07:54	2026-09-10 05:07:54
18	56	143	143	10	\N	2026-09-10 05:09:43	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:09:43	2026-09-10 05:09:43
19	58	144	144	10	\N	2026-09-10 05:11:32	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:11:32	2026-09-10 05:11:32
20	60	145	145	10	\N	2026-09-10 05:21:47	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:21:47	2026-09-10 05:21:47
21	62	146	146	10	\N	2026-09-10 05:33:48	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:33:48	2026-09-10 05:33:48
22	63	147	147	10	\N	2026-09-10 05:47:37	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:47:37	2026-09-10 05:47:37
23	65	148	148	10	\N	2026-09-10 05:54:36	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:54:36	2026-09-10 05:54:36
24	67	149	149	10	\N	2026-09-10 05:56:11	\N	\N	0	ACTIVE	\N	\N	2026-09-10 05:56:11	2026-09-10 05:56:11
25	69	150	150	10	\N	2026-09-10 06:03:39	\N	\N	0	ACTIVE	\N	\N	2026-09-10 06:03:39	2026-09-10 06:03:39
26	71	151	151	10	\N	2026-09-10 06:05:13	\N	\N	0	ACTIVE	\N	\N	2026-09-10 06:05:13	2026-09-10 06:05:13
27	73	152	152	10	\N	2026-09-10 06:06:36	\N	\N	0	ACTIVE	\N	\N	2026-09-10 06:06:36	2026-09-10 06:06:36
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: features; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.features (id, name, scope, value, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: grades; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.grades (id, stage_id, slug, name_ar, "position", created_at, updated_at) FROM stdin;
1	1	أول-ابتدائي	الصف الأول الابتدائي	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	1	ثاني-ابتدائي	الصف الثاني الابتدائي	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	1	ثالث-ابتدائي	الصف الثالث الابتدائي	2	2026-09-04 06:01:24	2026-09-04 06:01:24
4	1	رابع-ابتدائي	الصف الرابع الابتدائي	3	2026-09-04 06:01:24	2026-09-04 06:01:24
5	1	خامس-ابتدائي	الصف الخامس الابتدائي	4	2026-09-04 06:01:24	2026-09-04 06:01:24
6	1	سادس-ابتدائي	الصف السادس الابتدائي	5	2026-09-04 06:01:24	2026-09-04 06:01:24
7	2	أول-متوسط	الصف الأول المتوسط	6	2026-09-04 06:01:24	2026-09-04 06:01:24
8	2	ثاني-متوسط	الصف الثاني المتوسط	7	2026-09-04 06:01:24	2026-09-04 06:01:24
9	2	ثالث-متوسط	الصف الثالث المتوسط	8	2026-09-04 06:01:24	2026-09-04 06:01:24
10	3	أول-ثانوي	الصف الأول الثانوي	9	2026-09-04 06:01:24	2026-09-04 06:01:24
11	3	ثاني-ثانوي	الصف الثاني الثانوي	10	2026-09-04 06:01:24	2026-09-04 06:01:24
12	3	ثالث-ثانوي	الصف الثالث الثانوي	11	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: internal_links; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.internal_links (id, run_id, from_path, to_path, anchor, placement, nofollow) FROM stdin;
\.


--
-- Data for Name: invoices; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.invoices (id, order_id, number, issued_at, snapshot, created_at, updated_at) FROM stdin;
1	127	INV-2026-1127	2026-09-09 03:50:22	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"inv-1788915022@example.com","orgName":"\\u0645\\u062f\\u0627\\u0631\\u0633 \\u0627\\u0644\\u0646\\u062e\\u0628\\u0629 \\u0627\\u0644\\u0623\\u0647\\u0644\\u064a\\u0629","vatNumber":"300556677800003"},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_127","paidAt":"2026-09-09T03:50:22+03:00"}}	2026-09-09 03:50:22	2026-09-09 03:50:22
2	128	INV-2026-1128	2026-09-09 03:50:43	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1788915042372@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_128","paidAt":"2026-09-09T03:50:43+03:00"}}	2026-09-09 03:50:45	2026-09-09 03:50:45
3	129	INV-2026-1129	2026-09-09 03:59:34	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"rf-1788915574@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_129","paidAt":"2026-09-09T03:59:34+03:00"}}	2026-09-09 03:59:34	2026-09-09 03:59:34
4	130	INV-2026-1130	2026-09-09 04:00:08	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1788915607758@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_130","paidAt":"2026-09-09T04:00:08+03:00"}}	2026-09-09 04:00:10	2026-09-09 04:00:10
5	132	INV-2026-1132	2026-09-09 04:26:24	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1788917183419@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_132","paidAt":"2026-09-09T04:26:24+03:00"}}	2026-09-09 04:26:26	2026-09-09 04:26:26
6	133	INV-2026-1133	2026-09-09 04:51:07	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1788918666196@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_133","paidAt":"2026-09-09T04:51:07+03:00"}}	2026-09-09 04:51:08	2026-09-09 04:51:08
7	134	INV-2026-1134	2026-09-09 04:58:27	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1788919105742@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_134","paidAt":"2026-09-09T04:58:27+03:00"}}	2026-09-09 04:58:28	2026-09-09 04:58:28
8	135	INV-2026-1135	2026-09-10 04:25:28	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789003527149@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_135","paidAt":"2026-09-10T04:25:28+03:00"}}	2026-09-10 04:25:29	2026-09-10 04:25:29
9	136	INV-2026-1136	2026-09-10 04:29:09	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789003748151@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_136","paidAt":"2026-09-10T04:29:09+03:00"}}	2026-09-10 04:29:10	2026-09-10 04:29:10
10	137	INV-2026-1137	2026-09-10 04:35:07	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789004105645@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_137","paidAt":"2026-09-10T04:35:07+03:00"}}	2026-09-10 04:35:08	2026-09-10 04:35:08
11	138	INV-2026-1138	2026-09-10 04:51:54	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789005113487@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_138","paidAt":"2026-09-10T04:51:54+03:00"}}	2026-09-10 04:51:56	2026-09-10 04:51:56
12	139	INV-2026-1139	2026-09-10 04:59:59	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789005598011@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_139","paidAt":"2026-09-10T04:59:59+03:00"}}	2026-09-10 05:00:01	2026-09-10 05:00:01
13	140	INV-2026-1140	2026-09-10 05:01:42	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789005698745@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_140","paidAt":"2026-09-10T05:01:42+03:00"}}	2026-09-10 05:01:44	2026-09-10 05:01:44
14	141	INV-2026-1141	2026-09-10 05:04:11	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789005849804@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_141","paidAt":"2026-09-10T05:04:11+03:00"}}	2026-09-10 05:04:13	2026-09-10 05:04:13
15	142	INV-2026-1142	2026-09-10 05:07:54	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789006072468@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_142","paidAt":"2026-09-10T05:07:54+03:00"}}	2026-09-10 05:07:56	2026-09-10 05:07:56
16	143	INV-2026-1143	2026-09-10 05:09:43	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789006182300@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_143","paidAt":"2026-09-10T05:09:43+03:00"}}	2026-09-10 05:09:45	2026-09-10 05:09:45
17	144	INV-2026-1144	2026-09-10 05:11:32	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789006291385@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_144","paidAt":"2026-09-10T05:11:32+03:00"}}	2026-09-10 05:11:34	2026-09-10 05:11:34
18	145	INV-2026-1145	2026-09-10 05:21:47	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789006906085@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_145","paidAt":"2026-09-10T05:21:47+03:00"}}	2026-09-10 05:21:49	2026-09-10 05:21:49
19	146	INV-2026-1146	2026-09-10 05:33:48	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789007627465@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_146","paidAt":"2026-09-10T05:33:48+03:00"}}	2026-09-10 05:33:50	2026-09-10 05:33:50
20	148	INV-2026-1148	2026-09-10 05:54:36	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789008874712@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_148","paidAt":"2026-09-10T05:54:36+03:00"}}	2026-09-10 05:54:38	2026-09-10 05:54:38
21	149	INV-2026-1149	2026-09-10 05:56:11	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789008970378@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_149","paidAt":"2026-09-10T05:56:11+03:00"}}	2026-09-10 05:56:13	2026-09-10 05:56:13
22	150	INV-2026-1150	2026-09-10 06:03:39	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789009418127@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_150","paidAt":"2026-09-10T06:03:39+03:00"}}	2026-09-10 06:03:41	2026-09-10 06:03:41
23	151	INV-2026-1151	2026-09-10 06:05:13	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789009512162@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_151","paidAt":"2026-09-10T06:05:13+03:00"}}	2026-09-10 06:05:14	2026-09-10 06:05:14
24	152	INV-2026-1152	2026-09-10 06:06:36	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0646\\u0648\\u0631\\u0629 \\u0627\\u0644\\u0639\\u062a\\u064a\\u0628\\u064a","email":"ty-1789009595370@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_152","paidAt":"2026-09-10T06:06:36+03:00"}}	2026-09-10 06:06:37	2026-09-10 06:06:37
25	147	INV-2026-1147	2026-09-10 05:47:37	{"seller":{"name":"\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629","location":"\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u060c \\u0627\\u0644\\u0645\\u0645\\u0644\\u0643\\u0629 \\u0627\\u0644\\u0639\\u0631\\u0628\\u064a\\u0629 \\u0627\\u0644\\u0633\\u0639\\u0648\\u062f\\u064a\\u0629","registration":"\\u0648\\u062b\\u064a\\u0642\\u0629 \\u0627\\u0644\\u0639\\u0645\\u0644 \\u0627\\u0644\\u062d\\u0631 \\u2014 \\u0631\\u0642\\u0645 FL-374113209","email":"arodhaditha@gmail.com","whatsapp":"+966 50 919 4647","vatRegistered":false,"vatNumber":null},"buyer":{"name":"\\u0625\\u062b\\u0628\\u0627\\u062a \\u0627\\u0644\\u0647\\u0648\\u064a\\u0651\\u0629","email":"ssr-1789008457@example.com","orgName":null,"vatNumber":null},"lines":[{"i":1,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","qty":1,"unitMinor":3200,"totalMinor":3200}],"totals":{"grossMinor":3200,"productDiscountMinor":0,"couponDiscountMinor":0,"totalMinor":3200,"currency":"SAR"},"payment":{"provider":"fake","method":null,"maskedInstrument":null,"reference":"fake_chg_147","paidAt":"2026-09-10T05:47:37+03:00"}}	2026-09-10 09:59:33	2026-09-10 09:59:33
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
\.


--
-- Data for Name: kinds; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.kinds (id, slug, name_ar, "position", created_at, updated_at) FROM stdin;
1	تحضير	تحضير	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	عرض بوربوينت	عرض بوربوينت	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	أوراق عمل	أوراق عمل	2	2026-09-04 06:01:24	2026-09-04 06:01:24
4	اختبارات	اختبارات	3	2026-09-04 06:01:24	2026-09-04 06:01:24
5	توزيع	توزيع	4	2026-09-04 06:01:24	2026-09-04 06:01:24
6	وسائل تعليمية	وسائل تعليمية	5	2026-09-04 06:01:24	2026-09-04 06:01:24
7	حلول	حلول	6	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: locales; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.locales (code, name_ar, name_en, hreflang, direction, url_prefix, is_default, is_active, is_routable, "position", created_at, updated_at) FROM stdin;
ar	العربية	Arabic	ar-SA	rtl	\N	t	t	t	0	2026-09-04 06:01:23	2026-09-04 06:01:23
en	الإنجليزية	English	en	ltr	en	f	t	f	1	2026-09-04 06:01:23	2026-09-04 09:33:01
\.


--
-- Data for Name: magic_links; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.magic_links (id, token_hash, email, expires_at, used_at, created_at, updated_at) FROM stdin;
1	4eaa79354664bd1a139f3a9c6f331a55786ae306150ed8b49700d049cbdc8143	probe@example.com	2026-09-09 02:21:19	\N	2026-09-09 02:06:19	2026-09-09 02:06:19
2	7c2691739358ca47ad91cacf6dc8fc8c3b3ab5e906680dcec5d278932de4e6b6	e2e-1788909162463@example.com	2026-09-09 02:27:44	\N	2026-09-09 02:12:44	2026-09-09 02:12:44
23	898aa7d23e4468580e2a8eba5d8d6258c1ab439462b31d8b62ef913180112539	e2e-1789006079857@example.com	2026-09-10 05:23:01	2026-09-10 05:08:02	2026-09-10 05:08:01	2026-09-10 05:08:02
3	3930c212e35b2a3036f41de389f021128a82cecf0b4b0f8317b313d46cf8cec4	e2e-1788909175507@example.com	2026-09-09 02:27:57	2026-09-09 02:12:59	2026-09-09 02:12:57	2026-09-09 02:12:59
4	cdbd9d84e8fbb829e25b5132430db6b5a0637dbfd7b03e56fa9c7807210b20ca	probe2@example.com	2026-09-09 02:29:03	2026-09-09 02:14:03	2026-09-09 02:14:03	2026-09-09 02:14:03
5	dd8346a5f6b9b78e9ff6fd2a184bef828ee27dfe83b928b98b0880dd78f140ac	e2e-1788909310333@example.com	2026-09-09 02:30:14	2026-09-09 02:15:16	2026-09-09 02:15:14	2026-09-09 02:15:16
24	c1845b6ddebf7e674eb2eb6ef14c56e41c69baebad76ec0597564dcde818fd9b	e2e-1789006187663@example.com	2026-09-10 05:24:49	2026-09-10 05:09:50	2026-09-10 05:09:49	2026-09-10 05:09:50
6	1578ebfd47e431a30b15eddf76777ce1aa4258ca323610f53ff3c4ee6cabdadd	e2e-1788909345802@example.com	2026-09-09 02:30:47	2026-09-09 02:15:48	2026-09-09 02:15:47	2026-09-09 02:15:48
7	0c50d4dbe7c687cead32eecb83e9b2562bb1338f612e8b8c2211db9d6b97f7e6	throttle-probe@example.com	2026-09-09 02:31:54	\N	2026-09-09 02:16:54	2026-09-09 02:16:54
8	7c411e0256b0d4a723f228f579c4f0fc29c9c5355ec4057c6c8862278695d09b	e2e-1788909419629@example.com	2026-09-09 02:32:01	2026-09-09 02:17:01	2026-09-09 02:17:01	2026-09-09 02:17:01
25	780d657564ef968a786fdeb5765677b58224658245a203086d0d4ecf02ee548e	e2e-1789006797869@example.com	2026-09-10 05:35:00	2026-09-10 05:20:00	2026-09-10 05:20:00	2026-09-10 05:20:00
9	3293355d1b485e6c28c97f21dcd9318ae5254464df0800bcccb74e10cf64294d	e2e-1788909436606@example.com	2026-09-09 02:32:18	2026-09-09 02:17:19	2026-09-09 02:17:18	2026-09-09 02:17:19
10	31244ef377ca30d7387f4d007669129d72bdfdea4bc4a603a8baed791cae2c52	e2e-1788918999455@example.com	2026-09-09 05:11:41	2026-09-09 04:56:42	2026-09-09 04:56:41	2026-09-09 04:56:42
11	02bf689f90c62f4b556cd60e4b35bb98d6db65f351e2cc1f106af8be26efcfd9	e2e-1789003380828@example.com	2026-09-10 04:38:03	2026-09-10 04:23:03	2026-09-10 04:23:03	2026-09-10 04:23:03
12	25014e11dea6c3e9beb4427ca36ef5c062a331cbb7eb969cf9884239e2304ce1	e2e-1789003624153@example.com	2026-09-10 04:42:06	2026-09-10 04:27:06	2026-09-10 04:27:06	2026-09-10 04:27:06
13	ccbb6efca278602d8910d592df093abf29efca47ade4d7eed3d7dafe646233cf	e2e-1789003632594@example.com	2026-09-10 04:42:14	2026-09-10 04:27:14	2026-09-10 04:27:14	2026-09-10 04:27:14
14	ec413c391a5e3d219e8320750609bab24e162b3c89c8ada77a10b6e6f35a3ad3	e2e-1789003639997@example.com	2026-09-10 04:42:21	2026-09-10 04:27:22	2026-09-10 04:27:21	2026-09-10 04:27:22
15	15daa6bf304bf84482e3b3e59d696f2fec926f35f7251afeb685fd6481d1af07	e2e-1789003660077@example.com	2026-09-10 04:42:42	2026-09-10 04:27:42	2026-09-10 04:27:42	2026-09-10 04:27:42
16	10c486a642a85b88b75af61e58b31d2fb77c257628e84cfece5daec8774a3883	e2e-1789004015378@example.com	2026-09-10 04:48:38	2026-09-10 04:33:38	2026-09-10 04:33:38	2026-09-10 04:33:38
17	bf6761306be8a249fb75eae831ae6988537dfbd0caf39d429f16d8d3589bd746	sup-1789004263@example.com	2026-09-10 04:52:43	\N	2026-09-10 04:37:43	2026-09-10 04:37:43
18	d80f833f5ddf48edff86027a0eb3e2969168f4d172cb455e6b1829366ef803c7	e2e-1789004839857@example.com	2026-09-10 05:02:21	2026-09-10 04:47:22	2026-09-10 04:47:21	2026-09-10 04:47:22
19	82688734f9da4f1d4bf9a72078b7cc547297a6a2862dae5499730d4b5d29dfd1	e2e-1789005500611@example.com	2026-09-10 05:13:22	2026-09-10 04:58:23	2026-09-10 04:58:22	2026-09-10 04:58:23
20	7e87b5f65213e432e7856f4a297af3da755eb5cde47b04aa4f5a0ea57e95437c	e2e-1789005603181@example.com	2026-09-10 05:15:05	2026-09-10 05:00:05	2026-09-10 05:00:05	2026-09-10 05:00:05
21	26eaaeba5fc77dd067d22a851899f62dc7335aa17a9dfc801181660c87578040	e2e-1789005741616@example.com	2026-09-10 05:17:23	2026-09-10 05:02:24	2026-09-10 05:02:23	2026-09-10 05:02:24
22	d239df112839f68860a5e03fd49b6af47e985b9740f8cecc013085d18f6fbb32	e2e-1789005968892@example.com	2026-09-10 05:21:11	2026-09-10 05:06:11	2026-09-10 05:06:11	2026-09-10 05:06:11
26	dae11ace3cff4a82bee962bd949747e014319a9b75cb7de420df07ac4e1fc8e5	e2e-1789007542780@example.com	2026-09-10 05:47:24	2026-09-10 05:32:25	2026-09-10 05:32:24	2026-09-10 05:32:25
27	5b36e7453c2ce36736c97a77594ce5bb2f6636dcb7135b605c0700952706171e	e2e-1789008784193@example.com	2026-09-10 06:08:06	2026-09-10 05:53:07	2026-09-10 05:53:06	2026-09-10 05:53:07
28	cfde4247fc0c9100e9a80a56ee26c09b8c6e0e3e9415bd7d748c308e13dfc196	e2e-1789008881202@example.com	2026-09-10 06:09:43	2026-09-10 05:54:43	2026-09-10 05:54:43	2026-09-10 05:54:43
29	4658cab714a63fa2fa3ce58070b51d551ba351acdaab72cf0a86f013ed62029b	e2e-1789009335141@example.com	2026-09-10 06:17:17	2026-09-10 06:02:17	2026-09-10 06:02:17	2026-09-10 06:02:17
30	eaa8a2e54bc638c22c7bf8f22667fc52853403b08728d63a5af60ad97f5c77ea	e2e-1789009425733@example.com	2026-09-10 06:18:49	2026-09-10 06:03:50	2026-09-10 06:03:49	2026-09-10 06:03:50
31	27f0a78861407c42c61aa71e1db71f7649a0f7a5ea323cfc70c10fa5fb4c190a	e2e-1789009516066@example.com	2026-09-10 06:20:17	2026-09-10 06:05:18	2026-09-10 06:05:17	2026-09-10 06:05:18
\.


--
-- Data for Name: material_requests; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.material_requests (id, stage_id, grade_id, subject_id, semester_id, kind, note, contact, contact_kind, source, query, status, decision_note, fingerprint, hits, first_seen_at, last_seen_at, created_at, updated_at) FROM stdin;
1	\N	\N	\N	\N	\N	\N	\N	\N	zero_result	اختبارات الفيزياء	open	\N	rtA	9	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43
2	\N	\N	\N	\N	تحضير	\N	\N	\N	form	\N	open	\N	rtB	7	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43
3	\N	\N	\N	\N	\N	أحتاج ملف تحضير لمادة غير مذكورة في القائمة	\N	\N	form	\N	open	\N	rtC	5	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43	2026-09-06 07:33:43
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_08_31_000001_create_education_tables	1
5	2026_08_31_000002_create_catalog_tables	1
6	2026_08_31_000003_create_asset_tables	1
7	2026_08_31_000004_create_customer_tables	1
8	2026_08_31_000005_create_commerce_tables	1
9	2026_08_31_000006_create_entitlement_tables	1
10	2026_08_31_000007_create_review_tables	1
11	2026_08_31_000008_create_platform_tables	1
12	2026_08_31_010001_create_search_platform_tables	1
13	2026_08_31_020000_add_commerce_checkout_columns	1
14	2026_08_31_030001_add_asset_to_download_grants	1
15	2026_08_31_040001_create_seo_tables	1
16	2026_08_31_050001_create_review_domain_tables	1
17	2026_08_31_060001_create_admin_tables	1
18	2026_08_31_070001_create_content_tables	1
19	2026_09_02_010000_create_design_tables	1
20	2026_09_02_020000_create_product_layouts_table	1
21	2026_09_02_030000_create_i18n_tables	1
22	2026_09_02_040000_create_navigation_tables	1
23	2026_09_02_050000_create_navigation_operations_tables	1
24	2026_09_02_060000_create_link_graph_tables	1
25	2026_09_03_070000_create_material_requests_table	1
26	2026_09_04_080000_create_studio_pages_tables	1
27	2026_09_05_080000_create_studio_media_table	1
28	2026_09_05_090000_create_studio_templates_table	1
29	2026_09_05_100000_create_theme_versions_table	1
30	2026_09_06_080000_create_studio_symbols_tables	1
32	2026_09_07_080000_create_admin_notes_table	2
33	2026_09_08_080000_create_studio_entry_prefs_table	3
34	2026_09_08_090000_add_source_to_studio_templates	3
36	2026_09_09_080000_create_product_intelligence_tables	4
37	2026_09_09_100000_create_customer_sessions_table	5
38	2026_09_09_110000_add_confirmation_sent_at_to_orders_table	6
39	2026_09_09_120000_add_payment_failed_mail_to_orders_table	7
40	2026_09_09_130000_create_invoices_table	8
41	2026_09_09_140000_add_notified_at_to_refunds_table	9
42	2026_09_09_150000_add_reminder_columns_to_orders_table	10
43	2026_09_09_160000_create_quote_requests_table	11
44	2026_09_09_170000_add_handling_to_quote_requests_table	12
45	2026_09_10_100000_add_confirmation_resent_at_to_orders_table	13
46	2026_09_10_120000_create_analytics_tables	14
47	2026_09_10_130000_add_session_to_search_queries	15
48	2026_09_10_140000_create_ai_requests_table	16
51	2026_09_10_150000_add_provider_trace_to_search_queries	17
52	2026_09_10_160000_add_steps_to_navigation_publish_operations	18
53	2026_09_10_184458_create_features_table	19
\.


--
-- Data for Name: navigation_menus; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.navigation_menus (id, location, name, draft_nodes, published_nodes, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
1	header	الترويسة	[]	\N	1	\N	\N	\N	2026-09-04 07:01:43	2026-09-04 07:01:43
2	mobile	قائمة الجوّال	[]	\N	1	\N	\N	\N	2026-09-04 07:02:16	2026-09-04 07:02:16
3	footer	التذييل	[]	\N	1	\N	\N	\N	2026-09-04 07:02:18	2026-09-04 07:02:18
\.


--
-- Data for Name: navigation_publish_operations; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.navigation_publish_operations (id, idempotency_key, menu_id, actor_id, base_revision, target_revision, snapshot_revision, status, current_step, failure_code, failure_detail, verified_fingerprint, verified_at, started_at, completed_at, steps) FROM stdin;
\.


--
-- Data for Name: navigation_revisions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.navigation_revisions (id, menu_id, revision, nodes, fingerprint, node_count, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: not_found_hits; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.not_found_hits (id, path, referrer, hits, last_seen_at, created_at, updated_at, url_hash, first_seen_at) FROM stdin;
22	/best-sellers	\N	1	2026-09-04 14:34:39	2026-09-04 14:34:39	2026-09-04 14:34:39	9e0d952d3afce8b218b3db766e900434f24ed22e649bcbe30ec476a4e72b39b0	2026-09-04 14:34:39
23	/pages/g	http://127.0.0.1:3000/pages/g	16	2026-09-05 00:27:05	2026-09-04 16:46:18	2026-09-05 00:27:05	9201b75f5e505719cf69cc584bff1eb70424789b538f3fba41f752bd68519f0c	2026-09-04 16:46:18
25	/pages/disclosure-check	\N	1	2026-09-05 00:29:00	2026-09-05 00:29:00	2026-09-05 00:29:00	81abac619211c7b489ba0c81efb93639774103ff316342c0aa0104af4f2ceafc	2026-09-05 00:29:00
21	/new	\N	10	2026-09-06 07:00:31	2026-09-04 14:34:38	2026-09-06 07:00:31	c84eba962a6c7b674088c1683ea409553e487cd68a9ceee1e5c4d2e831d4f8d9	2026-09-04 14:34:38
9	/contact	http://127.0.0.1:3000/	283	2026-09-10 19:29:21	2026-09-04 07:18:29	2026-09-10 19:29:21	4eb950636535d637ee743743cfbc75724f464688f94a426d3dedea4097aeb993	2026-09-04 07:18:29
26	/products/%D8%A3%D9%88%D8%B1%D8%A7%D9%82-%D8%B9%D9%85%D9%84-%D9%84%D8%BA%D8%AA%D9%8A-%D8%AB%D8%A7%D9%84%D8%AB-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	\N	2	2026-09-05 01:40:53	2026-09-05 01:40:26	2026-09-05 01:40:53	cbdb272161f4e68823fe79975d886ca7eac35814055097e1013d666b8be51505	2026-09-05 01:40:26
40	/how-it-works	http://127.0.0.1:3000/	35	2026-09-10 08:10:14	2026-09-08 22:04:07	2026-09-10 08:10:14	1a320e543cd03ad16e9a9106967db5855c420d4a24f6fd60ef090fa408c601b4	2026-09-08 22:04:07
15	/products	\N	1	2026-09-04 14:26:01	2026-09-04 14:26:01	2026-09-04 14:26:01	d66ca189b1e76ae5fba6960e246b467cb759c0bce2093f634ab3cd4a34675c2c	2026-09-04 14:26:01
27	/kinds	\N	5	2026-09-06 07:01:18	2026-09-06 07:01:15	2026-09-06 07:01:18	3b1c9b2a0f36ad4e2908fca70fb47f2a97844f910ecfa658825d60e175b53c21	2026-09-06 07:01:15
11	/account	http://127.0.0.1:3000/cart	111	2026-09-10 21:37:45	2026-09-04 07:18:32	2026-09-10 21:37:45	441bb226c5de8e2183b8cce3e0928e77066605cdd9c810b9af0eee03265ede01	2026-09-04 07:18:32
8	/login	http://192.168.1.13:3000/search?q=	51	2026-09-10 06:05:16	2026-09-04 07:18:26	2026-09-10 06:05:16	7e93fba0bc7adda858a2500090e639e4f563a3965c7cf4c719fb56eb6b12c666	2026-09-04 07:18:25
7	/bundles	\N	1	2026-09-04 07:18:21	2026-09-04 07:18:22	2026-09-04 07:18:22	d9c1e0ca570d97aa198e61cff994c8fde2c5d54bb2ecfb4c5702ef92d7663305	2026-09-04 07:18:21
19	/storage/design-preview/DESIGN-PREVIEW-01.svg	\N	2	2026-09-04 14:27:06	2026-09-04 14:27:06	2026-09-04 14:27:06	a1ebd3f9837d9216d7f711827b46237cce6d1ce7561e697780b4be11d36ad3a0	2026-09-04 14:27:06
20	/offers	\N	44	2026-09-10 06:06:29	2026-09-04 14:34:36	2026-09-10 06:06:29	196e165cab75e2ae4bb168002e963c174261e55dbf3169950c80460ac297eb8d	2026-09-04 14:34:36
24	/pages/e2e-publish-check	\N	2	2026-09-04 23:28:41	2026-09-04 23:26:28	2026-09-04 23:28:41	6832accc255b7a64b4fbfbba35aff521ca7080e5003f6747dcbbe75814ca4c7f	2026-09-04 23:26:28
30	/account/orders	\N	32	2026-09-10 06:05:19	2026-09-06 07:02:06	2026-09-10 06:05:19	5585fb4140a93c6511eba7933c2cf3d3c4df78bab1c1ab6a303c593cdd8b9465	2026-09-06 07:02:06
34	/policies/terms	\N	14	2026-09-10 05:07:49	2026-09-06 07:03:07	2026-09-10 05:07:49	bf9333cacbdac8576185326f7b07b23250e139273c70d9e716ada20c7087ad32	2026-09-06 07:03:07
32	/account/downloads	\N	4	2026-09-06 07:18:05	2026-09-06 07:02:18	2026-09-06 07:18:05	83ff42afecdc7ff9b79893c59134913d95bc7780bc05f57dba23a23389c12e9f	2026-09-06 07:02:18
14	/policies/privacy	http://127.0.0.1:3000/policies/privacy	240	2026-09-10 19:29:21	2026-09-04 08:56:37	2026-09-10 19:29:21	a080c2b86aa7a6086e202b1ed01f03c5d3ac59b46cd3c052cda1147a2cd3b7d1	2026-09-04 08:56:37
18	/grades	\N	12	2026-09-06 07:01:13	2026-09-04 14:26:32	2026-09-06 07:01:13	fd33278cb4af27ee6b8bc2f26ef1822d3449fc19f3491560c650f0bada9a73a4	2026-09-04 14:26:32
3	/stages	http://127.0.0.1:3000/cart	194	2026-09-10 22:57:16	2026-09-04 06:10:27	2026-09-10 22:57:17	ccaf8367addf3d09dc4fab11fad602f02a68060380a0d906be10a24b8008b484	2026-09-04 06:10:27
31	/account/library	\N	13	2026-09-06 07:18:02	2026-09-06 07:02:11	2026-09-06 07:18:02	c0e8a14419e3da6044965ea93447c7aa912b52e39af145b00a571457d2d354dc	2026-09-06 07:02:11
33	/blog	http://127.0.0.1:3000/stages	83	2026-09-10 08:10:09	2026-09-06 07:03:01	2026-09-10 08:10:09	8caafe4386f958034d7ecffadb2d2282ffb1d275f330f13c1575e5a57bcc7b4b	2026-09-06 07:03:01
12	/shop	http://127.0.0.1:3000/shop	437	2026-09-10 20:47:14	2026-09-04 08:56:12	2026-09-10 20:47:14	26315ce1e9356d671a112ad44afb5efc7678f0a2e1333a26fbe5a8e8fe6e5483	2026-09-04 08:56:11
43	/stages/%D8%AB%D8%A7%D9%86%D9%88%D9%8A	\N	28	2026-09-10 06:06:31	2026-09-08 22:04:10	2026-09-10 06:06:31	497899cbe746f2e014bb0f8ce105ccfdde0f21a8273b9c99d4e46dbbfb44bb58	2026-09-08 22:04:10
37	/definitely-not-a-real-route-xyz	\N	5	2026-09-06 07:18:18	2026-09-06 07:05:43	2026-09-06 07:18:18	f239a3145ea6e0eeb69a20df06da291f5ec8a6308d4cdb99ee27c781496c1e9a	2026-09-06 07:05:43
42	/stages/%D9%85%D8%AA%D9%88%D8%B3%D8%B7	\N	32	2026-09-10 06:06:31	2026-09-08 22:04:10	2026-09-10 06:06:31	0a06d5c6824c4dc8fca9c8f2dfb999ee623fabdc70dc0d2a680a9982d94318a8	2026-09-08 22:04:10
41	/stages/%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A	http://127.0.0.1:3000/stages/%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A	172	2026-09-10 06:06:31	2026-09-08 22:04:10	2026-09-10 06:06:31	136471960d6a268228054852c49841c183d5d493c106218c8c70fa1ba38cd1d2	2026-09-08 22:04:10
35	/this-route-does-not-exist	\N	4	2026-09-06 07:03:14	2026-09-06 07:03:12	2026-09-06 07:03:14	dcbf29e49b88686f744717990c9540a4919a5d71a9e9c4cdf506585702db0250	2026-09-06 07:03:12
10	/request	http://127.0.0.1:3000/shop	12	2026-09-10 05:02:18	2026-09-04 07:18:30	2026-09-10 05:02:18	0cfb9bc15849a53ca51260d850ebb9599a418bce7a79abbf555b6e31d5ba271e	2026-09-04 07:18:30
17	/products/dev-commerce-002	http://127.0.0.1:3000/shop	27	2026-09-10 20:47:54	2026-09-04 14:26:30	2026-09-10 20:47:54	5bf379e9e8ce9a505555a851d3d2af7e0c34715a74dfd65fafdd2dde01a37cb5	2026-09-04 14:26:30
6	/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%B3%D8%A7%D8%AF%D8%B3-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	\N	3	2026-09-10 07:38:10	2026-09-04 07:18:19	2026-09-10 07:38:10	3d551fb06963fb0980fd66e28139f81b7a76434f4fe7fbe655c5360e1c34380e	2026-09-04 07:18:19
4	/checkout	\N	45	2026-09-10 07:37:16	2026-09-04 07:18:11	2026-09-10 07:37:16	3ff05a7ed82155e747c587aff28ce4d47f2d79cb6029fd20efdc9d1ffff11806	2026-09-04 07:18:11
45	/products/%D8%B9%D8%B1%D8%B6-%D8%A8%D9%88%D8%B1%D8%A8%D9%88%D9%8A%D9%86%D8%AA-%D8%A7%D9%84%D8%B9%D9%84%D9%88%D9%85-%D8%B1%D8%A7%D8%A8%D8%B9-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%AB%D8%A7%D9%86%D9%8A	\N	22	2026-09-10 06:06:32	2026-09-08 22:04:12	2026-09-10 06:06:32	b2ed2afcdf6667de965c210d5252018ef1729d2463b0df866714402077cf1c4f	2026-09-08 22:04:12
38	/policies	\N	8	2026-09-10 05:07:49	2026-09-08 22:03:58	2026-09-10 05:07:49	e73dd2f7548beb94aca37b9ae650417393730349f3e8340f124c2f305340c33e	2026-09-08 22:03:58
61	/orders/TXZUjxJQInxcD3L2I4xMmFS8HtNZdECMWFcItLAkx00	\N	2	2026-09-09 02:31:11	2026-09-09 02:31:10	2026-09-09 02:31:11	c5fffde471c4e777047762a87e5ccbb8e2f3787a9c3ef0d6f7450db8f3ae9697	2026-09-09 02:31:10
67	/orders/p6_TfWVcScYVleM61lSIltlqUa1sJc0oU8U6OD_HBSw/invoice	http://127.0.0.1:3000/orders/p6_TfWVcScYVleM61lSIltlqUa1sJc0oU8U6OD_HBSw	1	2026-09-09 04:00:10	2026-09-09 04:00:10	2026-09-09 04:00:10	5bac044dc88a5d107bea53e9c188fbe5fb754c4b6d5ec9d5d90bf7dcdf586bd5	2026-09-09 04:00:10
62	/orders/tn5E7zEF_yj86yfqUHtN_sZ-CZjIC_UeSoo4wO1Zb94	\N	2	2026-09-09 03:42:25	2026-09-09 03:42:25	2026-09-09 03:42:25	4d9872bfd050c79f92aa833d7f1e7eefdcc1d9e8a9489d4bb4db74b5ce42db8c	2026-09-09 03:42:25
74	/orders/gQ5hwVY8fMdEzfzbtWWK1NIgbYz-ouwNamQdFrspiCc/invoice	http://127.0.0.1:3000/orders/gQ5hwVY8fMdEzfzbtWWK1NIgbYz-ouwNamQdFrspiCc	1	2026-09-10 05:07:56	2026-09-10 05:07:56	2026-09-10 05:07:56	4a27eb37c7b6fc39a37a4236c029b9bffd9c787c2547007067dab07502172223	2026-09-10 05:07:56
71	/orders/LVGnGwgBL2Tiygb68WeNNQBqvvbvITgov4riUujAEE4	\N	2	2026-09-09 04:51:07	2026-09-09 04:51:06	2026-09-09 04:51:07	9d4b0672eaad8d80f0064beae2526774661401e6f45731d64d59b8795736ad21	2026-09-09 04:51:06
63	/orders/S6fvMko2vkd6iaDCFl_OJGFHWPjfNc6uDTie0Se4EAI	\N	2	2026-09-09 03:50:43	2026-09-09 03:50:42	2026-09-09 03:50:43	02f3447eb895846f9ad2aef682f2120759d6ecc4929a99a30db6bc43e384b890	2026-09-09 03:50:42
72	/orders/LVGnGwgBL2Tiygb68WeNNQBqvvbvITgov4riUujAEE4/invoice	http://127.0.0.1:3000/orders/LVGnGwgBL2Tiygb68WeNNQBqvvbvITgov4riUujAEE4	1	2026-09-09 04:51:08	2026-09-09 04:51:08	2026-09-09 04:51:08	c208d88af8ab1c6bcbbd260d168cfd12b02dae1776a1c0f641d0ab810c774a45	2026-09-09 04:51:08
64	/orders/S6fvMko2vkd6iaDCFl_OJGFHWPjfNc6uDTie0Se4EAI/invoice	http://127.0.0.1:3000/orders/S6fvMko2vkd6iaDCFl_OJGFHWPjfNc6uDTie0Se4EAI	1	2026-09-09 03:50:45	2026-09-09 03:50:45	2026-09-09 03:50:45	1ebbbbe8087e64eb6b0a4c8ebdba277f872e1228df4410933c69fbea160adbef	2026-09-09 03:50:45
48	/subjects	\N	19	2026-09-10 11:53:19	2026-09-08 22:15:07	2026-09-10 11:53:19	abafe1454bf88746e2aa4ce2721444b7a9aa104dc1ab5c94d35c3bb8ff8b325d	2026-09-08 22:15:07
54	/subjects/%D8%A7%D9%84%D9%81%D9%8A%D8%B2%D9%8A%D8%A7%D8%A1	\N	10	2026-09-10 05:07:48	2026-09-08 22:15:11	2026-09-10 05:07:48	faf53b98d10bb5a414f260c84229a609521e0f39a9e2f37e97bb5801b05c331d	2026-09-08 22:15:11
65	/orders/0EpY7YYQsg_g6HPkXVZoB42PnAHxWCIZbe60DqUm8lo/invoice	\N	1	2026-09-09 03:59:36	2026-09-09 03:59:36	2026-09-09 03:59:36	75a024fe7bc8bafe498e6be4a69c295ccefc3f67fec619fdba67958be594b6a6	2026-09-09 03:59:36
47	/policies/refund	\N	164	2026-09-10 19:29:21	2026-09-08 22:05:18	2026-09-10 19:29:21	baf7cdd3a5fa3f752b491e50d97eb1a9fd78565f27a3e565e1d09a159f9046db	2026-09-08 22:05:18
50	/subjects/%D8%A7%D9%84%D9%84%D8%BA%D8%A9-%D8%A7%D9%84%D8%B9%D8%B1%D8%A8%D9%8A%D8%A9	\N	17	2026-09-10 06:06:32	2026-09-08 22:15:08	2026-09-10 06:06:32	1a216ba92dfd1507831d8377db8d2b3d23f028257c538d513056c3df0628f57b	2026-09-08 22:15:08
58	/compare	http://127.0.0.1:3000/shop	42	2026-09-10 06:06:30	2026-09-09 01:50:57	2026-09-10 06:06:30	a00971334b056054e1e69d472fe00ec05157634995151654b0f5e5dfb20b4e4f	2026-09-09 01:50:56
13	/help	\N	167	2026-09-10 19:29:21	2026-09-04 08:56:34	2026-09-10 19:29:21	c6ba053c827bc5d0a76937fd72a6052bb973801cc4e4b2dceea519f8fa7bec70	2026-09-04 08:56:33
75	/orders/2fqviGVbAhooBXnFLsz1mVXPDkFsD9PI_WTjNTAdz8A/invoice	http://127.0.0.1:3000/orders/2fqviGVbAhooBXnFLsz1mVXPDkFsD9PI_WTjNTAdz8A	1	2026-09-10 05:09:45	2026-09-10 05:09:45	2026-09-10 05:09:45	c40345773386fd2c37099d685ef6a59f4fe8adefa8d46bb2422e335f707a5017	2026-09-10 05:09:45
52	/subjects/%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA	\N	14	2026-09-10 05:33:42	2026-09-08 22:15:09	2026-09-10 05:33:42	e45483504d632dcbb597017c559a36e6855c58d7d48b58e3102f274052993df7	2026-09-08 22:15:09
55	/subjects/%D8%A7%D9%84%D9%83%D9%8A%D9%85%D9%8A%D8%A7%D8%A1	\N	8	2026-09-09 01:03:36	2026-09-08 22:15:11	2026-09-09 01:03:36	e9cc7963ac46a5e9440ba58d6d103f314cbc4fe959a5681155ae0ecaeea0dde9	2026-09-08 22:15:11
56	/subjects/%D8%A7%D9%84%D8%A3%D8%AD%D9%8A%D8%A7%D8%A1	\N	5	2026-09-09 01:03:36	2026-09-08 22:18:36	2026-09-09 01:03:36	f41b8a4d28d60e182d4956095d0b0ee7c187e58146e09df410783f6433c97f44	2026-09-08 22:18:36
60	/orders/A_IhpWBPcvSpeMQ2Kb1Q-xrbjGo-jLGtPlJlCkQm5ao	\N	2	2026-09-09 02:30:42	2026-09-09 02:30:40	2026-09-09 02:30:42	c6ee594f45102838237731b78e4277ce427c4e13cf928e0d4bfb152b80bdf10c	2026-09-09 02:30:40
57	/wishlist	http://127.0.0.1:3000/shop	37	2026-09-10 21:37:49	2026-09-09 01:50:01	2026-09-10 21:37:49	06440d17cbba11f44bc67d0b8bbc718213ed0f3cd7dd19f75747f9a5d5e7ccdf	2026-09-09 01:50:01
46	/about	http://127.0.0.1:3000/	166	2026-09-10 23:39:11	2026-09-08 22:05:10	2026-09-10 23:39:11	979bddc4a8caafdda41370de2a2e0781a1af2f53c9f36fb804df9af860a53cf5	2026-09-08 22:05:09
69	/orders/1oXps7uYlJng9IXrc5kPcryzELPSRFVP3evO8LswmzM	\N	2	2026-09-09 04:26:24	2026-09-09 04:26:23	2026-09-09 04:26:25	1c76f9af3be3884dafdc0cbd3bc3b29e9c0620f81c3bdd2ef34bf3e3b06c6278	2026-09-09 04:26:23
66	/orders/p6_TfWVcScYVleM61lSIltlqUa1sJc0oU8U6OD_HBSw	\N	2	2026-09-09 04:00:09	2026-09-09 04:00:07	2026-09-09 04:00:09	d4a4c1c87fea698384576a9be0d3d4ba581b3e994841efa22db11c8d55f081aa	2026-09-09 04:00:07
51	/subjects/%D8%A7%D9%84%D9%83%D9%81%D8%A7%D9%8A%D8%A7%D8%AA-%D8%A7%D9%84%D9%84%D8%BA%D9%88%D9%8A%D8%A9	\N	15	2026-09-10 06:06:32	2026-09-08 22:15:09	2026-09-10 06:06:32	3bc045f60ce638d4f6503c79d49be8077cf6c719fd7fbcbe17c64e599a8da20a	2026-09-08 22:15:09
73	/orders/gQ5hwVY8fMdEzfzbtWWK1NIgbYz-ouwNamQdFrspiCc	\N	2	2026-09-10 05:07:54	2026-09-10 05:07:53	2026-09-10 05:07:54	cc8992957f9cc292ce62f591bf8d32a0845e7c53fae28d359397f10730a58230	2026-09-10 05:07:53
68	/institutions	\N	15	2026-09-10 06:05:46	2026-09-09 04:18:46	2026-09-10 06:05:46	f04c479e1924fbe7062133e1746e37b068a1c6a13d013158c92d47ad23f8de9b	2026-09-09 04:18:46
70	/orders/1oXps7uYlJng9IXrc5kPcryzELPSRFVP3evO8LswmzM/invoice	http://127.0.0.1:3000/orders/1oXps7uYlJng9IXrc5kPcryzELPSRFVP3evO8LswmzM	1	2026-09-09 04:26:26	2026-09-09 04:26:26	2026-09-09 04:26:26	e399dd67f32ceca2f8f3cffa36697313c848d72924f4e4dad163f3c9fe0cadac	2026-09-09 04:26:26
53	/subjects/%D8%A7%D9%84%D8%B9%D9%84%D9%88%D9%85	\N	12	2026-09-10 05:07:48	2026-09-08 22:15:10	2026-09-10 05:07:48	081214bb6c8650c9ea6215e415c4373b42c86fc86279f63f5cc92b7be6d1d603	2026-09-08 22:15:10
49	/subjects/%D9%84%D8%BA%D8%AA%D9%8A	\N	17	2026-09-10 06:06:32	2026-09-08 22:15:07	2026-09-10 06:06:32	a2d5b64ff2eacf70b001906b98d9ddad3ab51251f1fff9d32bc7276a187173c4	2026-09-08 22:15:07
76	/orders/GZATS8W_qaE4Try_O2yCwMEtWemwaMkxd13wQ3CjGKQ	\N	9	2026-09-10 05:49:34	2026-09-10 05:47:46	2026-09-10 05:49:34	2cfa89a13609723960b9a672ffc15e1a9d84bba55aecf6e7ef06097ec0764b58	2026-09-10 05:47:46
77	/orders/inXAwmx5ln3NX3V62lx6EvNqXo6DyKphzCkEEI8GzEo	\N	2	2026-09-10 05:54:36	2026-09-10 05:54:35	2026-09-10 05:54:36	b90f60449da198e3998fb5c01ab9237d9052f0855b6304a164a1cfd224095143	2026-09-10 05:54:35
78	/orders/inXAwmx5ln3NX3V62lx6EvNqXo6DyKphzCkEEI8GzEo/invoice	http://127.0.0.1:3000/orders/inXAwmx5ln3NX3V62lx6EvNqXo6DyKphzCkEEI8GzEo	1	2026-09-10 05:54:38	2026-09-10 05:54:38	2026-09-10 05:54:38	c35b1a6fe33c3cf69fd60f8ac197a54763a9d61a18953f6ed0589da670268cd8	2026-09-10 05:54:38
79	/orders/8RI6dNz6YgrEfsvxnJnxmF5CRsDgv6tM16fckEHdl-E/invoice	http://127.0.0.1:3000/orders/8RI6dNz6YgrEfsvxnJnxmF5CRsDgv6tM16fckEHdl-E	1	2026-09-10 06:05:14	2026-09-10 06:05:14	2026-09-10 06:05:14	a41a85b4fe8dc44b546689fea651145a8b9ba944bca9962fc0746c8e67b3e05d	2026-09-10 06:05:14
59	/auth/magic	\N	42	2026-09-10 06:05:20	2026-09-09 02:12:58	2026-09-10 06:05:20	15473a7b9e78513b371c7afb29e503428b5bba311717de63d0179f3004f79edd	2026-09-09 02:12:58
44	/products/%D8%B9%D8%B1%D8%B6-%D8%A8%D9%88%D8%B1%D8%A8%D9%88%D9%8A%D9%86%D8%AA-%D8%A7%D9%84%D9%81%D9%8A%D8%B2%D9%8A%D8%A7%D8%A1-%D8%A3%D9%88%D9%84-%D8%AB%D8%A7%D9%86%D9%88%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	\N	27	2026-09-10 06:06:31	2026-09-08 22:04:12	2026-09-10 06:06:31	8bc2bbfd297ae31e5a566ecd9b2adc3a8db65ece46731015aad94e76dab2dcd0	2026-09-08 22:04:12
80	/products/%D8%AD%D9%84%D9%88%D9%84-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%AB%D8%A7%D9%84%D8%AB-%D9%85%D8%AA%D9%88%D8%B3%D8%B7-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%AB%D8%A7%D9%84%D8%AB	http://127.0.0.1:3000/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%B3%D8%A7%D8%AF%D8%B3-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	1	2026-09-10 07:37:08	2026-09-10 07:37:08	2026-09-10 07:37:08	253d6eab297b0a972487038cc038bc44bc81d383e11771b2f31b64f4d8c71f73	2026-09-10 07:37:08
81	/products/%D8%A3%D9%88%D8%B1%D8%A7%D9%82-%D8%B9%D9%85%D9%84-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%A3%D9%88%D9%84-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	http://127.0.0.1:3000/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%B3%D8%A7%D8%AF%D8%B3-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84	3	2026-09-10 07:40:47	2026-09-10 07:39:12	2026-09-10 07:40:47	4f64d4d8998f733a9df6fd09a63ed19281e462fbafb64ce2a599493c2f216d05	2026-09-10 07:39:12
82	/semesters	\N	1	2026-09-10 11:53:22	2026-09-10 11:53:22	2026-09-10 11:53:22	e29a57392ff6e1110dd991e4b8024ff04aec7a2013dc16059ae6f9abba4ac376	2026-09-10 11:53:22
83	/atlas	\N	1	2026-09-10 15:24:48	2026-09-10 15:24:48	2026-09-10 15:24:48	5aa07a598a29c65e7b852472ddd3b5d0920a4b6b262f07173d7ee3fe02819f3c	2026-09-10 15:24:48
86	/no-such-page	\N	1	2026-09-10 21:36:37	2026-09-10 21:36:37	2026-09-10 21:36:37	a6439e067fa5c9071b21dea8d920f0f428648d2a423e9387bcd7cada30f4f317	2026-09-10 21:36:37
2	/cart	http://127.0.0.1:3000/cart	247	2026-09-10 21:37:44	2026-09-04 06:03:49	2026-09-10 21:37:44	edf54f1e1e2f93ced8bdb2e689f75bb093b2f28a9bdae8bd845c33b21dc75ac8	2026-09-04 06:03:49
1	/	http://127.0.0.1:3000/stages	1402	2026-09-10 23:39:08	2026-09-04 06:01:33	2026-09-10 23:39:08	8a5edab282632443219e051e4ade2d1d5bbc671c781051bf1437897cbdfea0f1	2026-09-04 06:01:33
5	/search	http://192.168.1.13:3000/stages	193	2026-09-10 21:37:50	2026-09-04 07:18:15	2026-09-10 21:37:50	6fb5b7787ecacd9111098c7c0555980b0168c321be3d303790e5a69af68a8a62	2026-09-04 07:18:15
87	/institutional	\N	2	2026-09-11 00:39:11	2026-09-11 00:39:11	2026-09-11 00:39:11	7f168660a7344fcc87501913c2df6cec6660c0a05bd406ea03ec3822339407cc	2026-09-11 00:39:11
88	/orders/u5kzHqeZG4Nh0X_08r4J0eqrZCVU5cPfCMFqJwHJxQ4/invoice	\N	1	2026-09-11 00:39:40	2026-09-11 00:39:40	2026-09-11 00:39:40	3e680f8b95bc1aa7b0a31305c16eb9de3eb70fb0d3aadeffdcd71e70f5748012	2026-09-11 00:39:40
\.


--
-- Data for Name: order_items; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.order_items (id, order_id, product_id, title_snapshot, price_snapshot_minor, version_label, created_at, updated_at) FROM stdin;
1	1	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
2	2	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
3	3	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
4	4	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
5	5	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
6	6	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
7	7	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
8	8	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
9	9	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
10	10	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
11	11	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
12	12	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
13	13	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
14	14	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
15	15	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
16	16	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
17	17	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
18	18	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
19	19	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
20	20	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
21	21	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
22	22	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
23	23	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
24	24	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
25	25	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
26	26	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
27	27	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
28	28	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
29	29	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
30	30	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	3500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
31	31	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
32	32	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
33	33	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
34	34	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
35	35	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
36	36	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
37	37	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	2900	إصدار ١٤٤٧هـ	2026-09-04 06:01:26	2026-09-04 06:01:26
38	38	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
39	39	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
40	40	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
41	41	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
42	42	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
43	43	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
44	44	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
45	45	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:26	2026-09-04 06:01:26
46	46	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	2500	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
47	47	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
48	48	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
49	49	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
50	50	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
51	51	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
52	52	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	3000	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
53	53	7	توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول	1500	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
54	54	7	توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول	1500	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
55	55	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	3900	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
56	56	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	3900	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
57	57	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	3900	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
58	58	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	3900	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
59	59	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	3900	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
60	60	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
61	61	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
62	62	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
63	63	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
64	64	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
65	65	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
66	66	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
67	67	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
68	68	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
69	69	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
70	70	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	4400	\N	2026-09-04 06:01:27	2026-09-04 06:01:27
71	71	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
72	72	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
73	73	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
74	74	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	إصدار ١٤٤٧هـ	2026-09-04 06:01:27	2026-09-04 06:01:27
75	75	11	وسائل تعليمية القرآن الكريم — الصف الثاني الابتدائي — الفصل الدراسي الأول	2200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
76	76	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
77	77	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
78	78	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
79	79	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
80	80	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	2800	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
81	81	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
82	82	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
83	83	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
84	84	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
85	85	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
86	86	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
87	87	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	4200	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
88	88	15	عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني	4800	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
89	89	15	عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني	4800	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
90	90	15	عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني	4800	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
91	91	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
92	92	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
93	93	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
94	94	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
95	95	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
96	96	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	2600	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
97	97	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
98	98	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
99	99	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
100	100	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
101	101	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
102	102	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
103	103	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
104	104	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	2450	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
105	105	19	وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني	1900	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
106	106	19	وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني	1900	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
107	107	19	وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني	1900	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
108	108	21	حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث	3300	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
109	109	21	حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث	3300	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
110	110	21	حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث	3300	\N	2026-09-04 06:01:28	2026-09-04 06:01:28
111	111	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
112	112	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
113	113	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
114	114	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
115	115	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
116	116	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
117	117	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:28	2026-09-04 06:01:28
118	118	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
119	119	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	3280	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
120	120	24	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	4600	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
121	121	24	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	4600	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
122	122	24	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	4600	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
123	123	24	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	4600	إصدار ١٤٤٧هـ	2026-09-04 06:01:29	2026-09-04 06:01:29
124	124	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 02:30:38	2026-09-09 02:30:38
125	125	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 02:31:10	2026-09-09 02:31:10
126	126	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 03:41:05	2026-09-09 03:41:05
127	127	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 03:50:22	2026-09-09 03:50:22
128	128	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 03:50:42	2026-09-09 03:50:42
129	129	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 03:59:34	2026-09-09 03:59:34
130	130	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 04:00:07	2026-09-09 04:00:07
131	131	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 04:04:28	2026-09-09 04:04:28
132	132	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 04:26:23	2026-09-09 04:26:23
133	133	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 04:51:06	2026-09-09 04:51:06
134	134	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-09 04:58:25	2026-09-09 04:58:25
135	135	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 04:25:27	2026-09-10 04:25:27
136	136	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 04:29:08	2026-09-10 04:29:08
137	137	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 04:35:05	2026-09-10 04:35:05
138	138	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 04:51:53	2026-09-10 04:51:53
139	139	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 04:59:58	2026-09-10 04:59:58
140	140	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:01:38	2026-09-10 05:01:38
141	141	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:04:09	2026-09-10 05:04:09
142	142	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:07:52	2026-09-10 05:07:52
143	143	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:09:42	2026-09-10 05:09:42
144	144	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:11:31	2026-09-10 05:11:31
145	145	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:21:46	2026-09-10 05:21:46
146	146	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:33:47	2026-09-10 05:33:47
147	147	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:47:37	2026-09-10 05:47:37
148	148	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:54:34	2026-09-10 05:54:34
149	149	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 05:56:10	2026-09-10 05:56:10
150	150	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 06:03:38	2026-09-10 06:03:38
151	151	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 06:05:12	2026-09-10 06:05:12
152	152	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	3200	\N	2026-09-10 06:06:35	2026-09-10 06:06:35
153	153	2	[تجريبي للتطوير] منتج رقمي عليه تخفيض	25000	\N	2026-09-11 00:39:33	2026-09-11 00:39:33
\.


--
-- Data for Name: orders; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.orders (id, number, customer_id, email, phone, status, totals_gross_minor, discount_minor, coupon_minor, total_minor, vat_minor, order_key_hash, tax_org_name, tax_vat_number, created_at, updated_at, paid_at, marketing_opt_in, policy_consent_at, confirmation_sent_at, payment_failed_mail_charge_id, payment_failed_mail_at, reminder_stage, reminded_at, confirmation_resent_at) FROM stdin;
1	DESIGN-PREVIEW-03-01	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-03 06:01:26	f	\N	\N	\N	\N	0	\N	\N
2	DESIGN-PREVIEW-03-02	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-02 06:01:26	f	\N	\N	\N	\N	0	\N	\N
3	DESIGN-PREVIEW-03-03	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-01 06:01:26	f	\N	\N	\N	\N	0	\N	\N
4	DESIGN-PREVIEW-03-04	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-31 06:01:26	f	\N	\N	\N	\N	0	\N	\N
5	DESIGN-PREVIEW-03-05	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-30 06:01:26	f	\N	\N	\N	\N	0	\N	\N
6	DESIGN-PREVIEW-03-06	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-29 06:01:26	f	\N	\N	\N	\N	0	\N	\N
7	DESIGN-PREVIEW-03-07	\N	design-preview@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-28 06:01:26	f	\N	\N	\N	\N	0	\N	\N
8	DESIGN-PREVIEW-01-01	\N	design-preview@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-03 06:01:26	f	\N	\N	\N	\N	0	\N	\N
9	DESIGN-PREVIEW-01-02	\N	design-preview@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-02 06:01:26	f	\N	\N	\N	\N	0	\N	\N
10	DESIGN-PREVIEW-01-03	\N	design-preview@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-01 06:01:26	f	\N	\N	\N	\N	0	\N	\N
11	DESIGN-PREVIEW-01-04	\N	design-preview@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-31 06:01:26	f	\N	\N	\N	\N	0	\N	\N
12	DESIGN-PREVIEW-01-05	\N	design-preview@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-30 06:01:26	f	\N	\N	\N	\N	0	\N	\N
13	DESIGN-PREVIEW-07-01	\N	design-preview@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-03 06:01:26	f	\N	\N	\N	\N	0	\N	\N
14	DESIGN-PREVIEW-07-02	\N	design-preview@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-02 06:01:26	f	\N	\N	\N	\N	0	\N	\N
15	DESIGN-PREVIEW-07-03	\N	design-preview@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-01 06:01:26	f	\N	\N	\N	\N	0	\N	\N
16	DESIGN-PREVIEW-07-04	\N	design-preview@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-31 06:01:26	f	\N	\N	\N	\N	0	\N	\N
17	DESIGN-PREVIEW-10-01	\N	design-preview@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-03 06:01:26	f	\N	\N	\N	\N	0	\N	\N
18	DESIGN-PREVIEW-10-02	\N	design-preview@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-09-02 06:01:26	f	\N	\N	\N	\N	0	\N	\N
19	DESIGN-PREVIEW-R01-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-26 06:01:26	f	\N	\N	\N	\N	0	\N	\N
20	DESIGN-PREVIEW-R01-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-18 06:01:26	f	\N	\N	\N	\N	0	\N	\N
21	DESIGN-PREVIEW-R01-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-11 06:01:26	f	\N	\N	\N	\N	0	\N	\N
22	DESIGN-PREVIEW-R01-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-03 06:01:26	f	\N	\N	\N	\N	0	\N	\N
23	DESIGN-PREVIEW-R01-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-27 06:01:26	f	\N	\N	\N	\N	0	\N	\N
24	DESIGN-PREVIEW-R01-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-20 06:01:26	f	\N	\N	\N	\N	0	\N	\N
25	DESIGN-PREVIEW-R01-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-12 06:01:26	f	\N	\N	\N	\N	0	\N	\N
26	DESIGN-PREVIEW-R01-08	8	design-preview-reviewer-08@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-05 06:01:26	f	\N	\N	\N	\N	0	\N	\N
27	DESIGN-PREVIEW-R01-09	9	design-preview-reviewer-09@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-06-28 06:01:26	f	\N	\N	\N	\N	0	\N	\N
28	DESIGN-PREVIEW-R01-10	10	design-preview-reviewer-10@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-06-20 06:01:26	f	\N	\N	\N	\N	0	\N	\N
29	DESIGN-PREVIEW-R01-11	11	design-preview-reviewer-11@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-06-13 06:01:26	f	\N	\N	\N	\N	0	\N	\N
30	DESIGN-PREVIEW-R01-12	12	design-preview-reviewer-12@example.invalid	\N	COMPLETED	3500	0	0	3500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-06-05 06:01:26	f	\N	\N	\N	\N	0	\N	\N
31	DESIGN-PREVIEW-R02-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-24 06:01:26	f	\N	\N	\N	\N	0	\N	\N
32	DESIGN-PREVIEW-R02-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-14 06:01:26	f	\N	\N	\N	\N	0	\N	\N
33	DESIGN-PREVIEW-R02-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-05 06:01:26	f	\N	\N	\N	\N	0	\N	\N
34	DESIGN-PREVIEW-R02-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-27 06:01:26	f	\N	\N	\N	\N	0	\N	\N
35	DESIGN-PREVIEW-R02-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-18 06:01:26	f	\N	\N	\N	\N	0	\N	\N
36	DESIGN-PREVIEW-R02-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-08 06:01:26	f	\N	\N	\N	\N	0	\N	\N
37	DESIGN-PREVIEW-R02-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	2900	0	0	2900	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-06-29 06:01:26	f	\N	\N	\N	\N	0	\N	\N
38	DESIGN-PREVIEW-R03-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-26 06:01:26	f	\N	\N	\N	\N	0	\N	\N
39	DESIGN-PREVIEW-R03-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-19 06:01:26	f	\N	\N	\N	\N	0	\N	\N
40	DESIGN-PREVIEW-R03-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-13 06:01:26	f	\N	\N	\N	\N	0	\N	\N
41	DESIGN-PREVIEW-R03-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-08-06 06:01:26	f	\N	\N	\N	\N	0	\N	\N
42	DESIGN-PREVIEW-R03-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-30 06:01:26	f	\N	\N	\N	\N	0	\N	\N
43	DESIGN-PREVIEW-R03-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-23 06:01:26	f	\N	\N	\N	\N	0	\N	\N
44	DESIGN-PREVIEW-R03-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-16 06:01:26	f	\N	\N	\N	\N	0	\N	\N
45	DESIGN-PREVIEW-R03-08	8	design-preview-reviewer-08@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:26	2026-09-04 06:01:26	2026-07-10 06:01:26	f	\N	\N	\N	\N	0	\N	\N
46	DESIGN-PREVIEW-R03-09	9	design-preview-reviewer-09@example.invalid	\N	COMPLETED	2500	0	0	2500	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-03 06:01:27	f	\N	\N	\N	\N	0	\N	\N
47	DESIGN-PREVIEW-R04-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-24 06:01:27	f	\N	\N	\N	\N	0	\N	\N
48	DESIGN-PREVIEW-R04-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-16 06:01:27	f	\N	\N	\N	\N	0	\N	\N
49	DESIGN-PREVIEW-R04-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-07 06:01:27	f	\N	\N	\N	\N	0	\N	\N
50	DESIGN-PREVIEW-R04-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-29 06:01:27	f	\N	\N	\N	\N	0	\N	\N
51	DESIGN-PREVIEW-R04-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-20 06:01:27	f	\N	\N	\N	\N	0	\N	\N
52	DESIGN-PREVIEW-R04-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	3000	0	0	3000	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-12 06:01:27	f	\N	\N	\N	\N	0	\N	\N
53	DESIGN-PREVIEW-R05-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	1500	0	0	1500	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-15 06:01:27	f	\N	\N	\N	\N	0	\N	\N
54	DESIGN-PREVIEW-R05-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	1500	0	0	1500	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-27 06:01:27	f	\N	\N	\N	\N	0	\N	\N
55	DESIGN-PREVIEW-R06-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3900	0	0	3900	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-25 06:01:27	f	\N	\N	\N	\N	0	\N	\N
56	DESIGN-PREVIEW-R06-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3900	0	0	3900	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-17 06:01:27	f	\N	\N	\N	\N	0	\N	\N
57	DESIGN-PREVIEW-R06-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3900	0	0	3900	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-09 06:01:27	f	\N	\N	\N	\N	0	\N	\N
58	DESIGN-PREVIEW-R06-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	3900	0	0	3900	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-02 06:01:27	f	\N	\N	\N	\N	0	\N	\N
59	DESIGN-PREVIEW-R06-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	3900	0	0	3900	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-25 06:01:27	f	\N	\N	\N	\N	0	\N	\N
60	DESIGN-PREVIEW-R07-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-30 06:01:27	f	\N	\N	\N	\N	0	\N	\N
61	DESIGN-PREVIEW-R07-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-26 06:01:27	f	\N	\N	\N	\N	0	\N	\N
62	DESIGN-PREVIEW-R07-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-23 06:01:27	f	\N	\N	\N	\N	0	\N	\N
63	DESIGN-PREVIEW-R07-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-20 06:01:27	f	\N	\N	\N	\N	0	\N	\N
64	DESIGN-PREVIEW-R07-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-17 06:01:27	f	\N	\N	\N	\N	0	\N	\N
65	DESIGN-PREVIEW-R07-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-13 06:01:27	f	\N	\N	\N	\N	0	\N	\N
66	DESIGN-PREVIEW-R07-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-10 06:01:27	f	\N	\N	\N	\N	0	\N	\N
67	DESIGN-PREVIEW-R07-08	8	design-preview-reviewer-08@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-07 06:01:27	f	\N	\N	\N	\N	0	\N	\N
68	DESIGN-PREVIEW-R07-09	9	design-preview-reviewer-09@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-04 06:01:27	f	\N	\N	\N	\N	0	\N	\N
69	DESIGN-PREVIEW-R07-10	10	design-preview-reviewer-10@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-31 06:01:27	f	\N	\N	\N	\N	0	\N	\N
70	DESIGN-PREVIEW-R07-11	11	design-preview-reviewer-11@example.invalid	\N	COMPLETED	4400	0	0	4400	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-07-28 06:01:27	f	\N	\N	\N	\N	0	\N	\N
71	DESIGN-PREVIEW-R08-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3200	0	0	3200	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-26 06:01:27	f	\N	\N	\N	\N	0	\N	\N
72	DESIGN-PREVIEW-R08-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3200	0	0	3200	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-20 06:01:27	f	\N	\N	\N	\N	0	\N	\N
73	DESIGN-PREVIEW-R08-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3200	0	0	3200	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-13 06:01:27	f	\N	\N	\N	\N	0	\N	\N
74	DESIGN-PREVIEW-R08-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	3200	0	0	3200	0	\N	\N	\N	2026-09-04 06:01:27	2026-09-04 06:01:27	2026-08-07 06:01:27	f	\N	\N	\N	\N	0	\N	\N
75	DESIGN-PREVIEW-R09-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2200	0	0	2200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-20 06:01:28	f	\N	\N	\N	\N	0	\N	\N
76	DESIGN-PREVIEW-R10-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-30 06:01:28	f	\N	\N	\N	\N	0	\N	\N
77	DESIGN-PREVIEW-R10-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-27 06:01:28	f	\N	\N	\N	\N	0	\N	\N
78	DESIGN-PREVIEW-R10-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-24 06:01:28	f	\N	\N	\N	\N	0	\N	\N
79	DESIGN-PREVIEW-R10-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-21 06:01:28	f	\N	\N	\N	\N	0	\N	\N
80	DESIGN-PREVIEW-R10-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	2800	0	0	2800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-18 06:01:28	f	\N	\N	\N	\N	0	\N	\N
81	DESIGN-PREVIEW-R11-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-09-01 06:01:28	f	\N	\N	\N	\N	0	\N	\N
82	DESIGN-PREVIEW-R11-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-30 06:01:28	f	\N	\N	\N	\N	0	\N	\N
83	DESIGN-PREVIEW-R11-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-29 06:01:28	f	\N	\N	\N	\N	0	\N	\N
84	DESIGN-PREVIEW-R11-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-27 06:01:28	f	\N	\N	\N	\N	0	\N	\N
85	DESIGN-PREVIEW-R11-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-26 06:01:28	f	\N	\N	\N	\N	0	\N	\N
86	DESIGN-PREVIEW-R11-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-25 06:01:28	f	\N	\N	\N	\N	0	\N	\N
87	DESIGN-PREVIEW-R11-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	4200	0	0	4200	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-24 06:01:28	f	\N	\N	\N	\N	0	\N	\N
88	DESIGN-PREVIEW-R13-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	4800	0	0	4800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-11 06:01:28	f	\N	\N	\N	\N	0	\N	\N
89	DESIGN-PREVIEW-R13-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	4800	0	0	4800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-20 06:01:28	f	\N	\N	\N	\N	0	\N	\N
90	DESIGN-PREVIEW-R13-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	4800	0	0	4800	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-06-28 06:01:28	f	\N	\N	\N	\N	0	\N	\N
91	DESIGN-PREVIEW-R14-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-21 06:01:28	f	\N	\N	\N	\N	0	\N	\N
92	DESIGN-PREVIEW-R14-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-10 06:01:28	f	\N	\N	\N	\N	0	\N	\N
93	DESIGN-PREVIEW-R14-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-29 06:01:28	f	\N	\N	\N	\N	0	\N	\N
94	DESIGN-PREVIEW-R14-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-18 06:01:28	f	\N	\N	\N	\N	0	\N	\N
95	DESIGN-PREVIEW-R14-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-06 06:01:28	f	\N	\N	\N	\N	0	\N	\N
96	DESIGN-PREVIEW-R14-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	2600	0	0	2600	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-06-25 06:01:28	f	\N	\N	\N	\N	0	\N	\N
97	DESIGN-PREVIEW-R16-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-26 06:01:28	f	\N	\N	\N	\N	0	\N	\N
98	DESIGN-PREVIEW-R16-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-19 06:01:28	f	\N	\N	\N	\N	0	\N	\N
99	DESIGN-PREVIEW-R16-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-12 06:01:28	f	\N	\N	\N	\N	0	\N	\N
100	DESIGN-PREVIEW-R16-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-05 06:01:28	f	\N	\N	\N	\N	0	\N	\N
101	DESIGN-PREVIEW-R16-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-28 06:01:28	f	\N	\N	\N	\N	0	\N	\N
102	DESIGN-PREVIEW-R16-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-21 06:01:28	f	\N	\N	\N	\N	0	\N	\N
103	DESIGN-PREVIEW-R16-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-14 06:01:28	f	\N	\N	\N	\N	0	\N	\N
104	DESIGN-PREVIEW-R16-08	8	design-preview-reviewer-08@example.invalid	\N	COMPLETED	2450	0	0	2450	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-07 06:01:28	f	\N	\N	\N	\N	0	\N	\N
105	DESIGN-PREVIEW-R17-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	1900	0	0	1900	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-18 06:01:28	f	\N	\N	\N	\N	0	\N	\N
106	DESIGN-PREVIEW-R17-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	1900	0	0	1900	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-04 06:01:28	f	\N	\N	\N	\N	0	\N	\N
107	DESIGN-PREVIEW-R17-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	1900	0	0	1900	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-07-20 06:01:28	f	\N	\N	\N	\N	0	\N	\N
108	DESIGN-PREVIEW-R19-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3300	0	0	3300	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-22 06:01:28	f	\N	\N	\N	\N	0	\N	\N
109	DESIGN-PREVIEW-R19-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3300	0	0	3300	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-11 06:01:28	f	\N	\N	\N	\N	0	\N	\N
110	DESIGN-PREVIEW-R19-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3300	0	0	3300	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-01 06:01:28	f	\N	\N	\N	\N	0	\N	\N
111	DESIGN-PREVIEW-R20-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-29 06:01:28	f	\N	\N	\N	\N	0	\N	\N
112	DESIGN-PREVIEW-R20-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-26 06:01:28	f	\N	\N	\N	\N	0	\N	\N
113	DESIGN-PREVIEW-R20-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-22 06:01:28	f	\N	\N	\N	\N	0	\N	\N
114	DESIGN-PREVIEW-R20-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-19 06:01:28	f	\N	\N	\N	\N	0	\N	\N
115	DESIGN-PREVIEW-R20-05	5	design-preview-reviewer-05@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-15 06:01:28	f	\N	\N	\N	\N	0	\N	\N
116	DESIGN-PREVIEW-R20-06	6	design-preview-reviewer-06@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-11 06:01:28	f	\N	\N	\N	\N	0	\N	\N
117	DESIGN-PREVIEW-R20-07	7	design-preview-reviewer-07@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-08-08 06:01:28	f	\N	\N	\N	\N	0	\N	\N
118	DESIGN-PREVIEW-R20-08	8	design-preview-reviewer-08@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-04 06:01:29	f	\N	\N	\N	\N	0	\N	\N
119	DESIGN-PREVIEW-R20-09	9	design-preview-reviewer-09@example.invalid	\N	COMPLETED	3280	0	0	3280	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-01 06:01:29	f	\N	\N	\N	\N	0	\N	\N
120	DESIGN-PREVIEW-R22-01	1	design-preview-reviewer-01@example.invalid	\N	COMPLETED	4600	0	0	4600	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-29 06:01:29	f	\N	\N	\N	\N	0	\N	\N
121	DESIGN-PREVIEW-R22-02	2	design-preview-reviewer-02@example.invalid	\N	COMPLETED	4600	0	0	4600	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-24 06:01:29	f	\N	\N	\N	\N	0	\N	\N
122	DESIGN-PREVIEW-R22-03	3	design-preview-reviewer-03@example.invalid	\N	COMPLETED	4600	0	0	4600	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-20 06:01:29	f	\N	\N	\N	\N	0	\N	\N
123	DESIGN-PREVIEW-R22-04	4	design-preview-reviewer-04@example.invalid	\N	COMPLETED	4600	0	0	4600	0	\N	\N	\N	2026-09-04 06:01:29	2026-09-04 06:01:29	2026-08-15 06:01:29	f	\N	\N	\N	\N	0	\N	\N
124	TD-1124	23	ty-1788910237918@example.com	512345678	PAID	3200	0	0	3200	417	a793ce171ee43c4330bfae21546e8db22299f828ddc479585442c0988ff0266b	\N	\N	2026-09-09 02:30:38	2026-09-09 02:30:43	2026-09-09 02:30:42	f	2026-09-09 02:30:38	2026-09-09 02:30:43	\N	\N	0	\N	\N
125	TD-1125	24	ty-1788910270025@example.com	512345678	PAID	3200	0	0	3200	417	47bc85e576c82b4206f29469b47abaf5fdc39f625a83c79d0eb2d25bc2641e94	\N	\N	2026-09-09 02:31:10	2026-09-09 02:31:13	2026-09-09 02:31:11	f	2026-09-09 02:31:10	2026-09-09 02:31:13	\N	\N	0	\N	\N
126	TD-1126	25	pf-1788914465@example.com	512345678	AWAITING_PAYMENT	3200	0	0	3200	417	5fc9fd99cb817f16dae7c98ec2cf865095eaebe63f6eb726073743e46eb3428f	\N	\N	2026-09-09 03:41:05	2026-09-09 03:41:45	\N	f	2026-09-09 03:41:05	\N	chg_local_1	2026-09-09 03:41:45	0	\N	\N
127	TD-1127	26	inv-1788915022@example.com	512345678	PAID	3200	0	0	3200	417	9de4d9ad690984cd1648f42c5b8b27796c3e37fca8b0d822a39833ba77dc22dd	مدارس النخبة الأهلية	300556677800003	2026-09-09 03:50:22	2026-09-09 03:50:23	2026-09-09 03:50:22	f	2026-09-09 03:50:22	2026-09-09 03:50:23	\N	\N	0	\N	\N
128	TD-1128	27	ty-1788915042372@example.com	512345678	PAID	3200	0	0	3200	417	9da9b423e9dd5a9dc2f8215e0fb80d9a55f9aa6181c4ad5875c71f84e112e682	\N	\N	2026-09-09 03:50:42	2026-09-09 03:50:44	2026-09-09 03:50:43	f	2026-09-09 03:50:42	2026-09-09 03:50:44	\N	\N	0	\N	\N
129	TD-1129	28	rf-1788915574@example.com	512345678	REFUNDED	3200	0	0	3200	417	95a13500454f13e4e823a212c782d84ded5838e2190f8e5bd77bd0bf60494a64	\N	\N	2026-09-09 03:59:34	2026-09-09 03:59:35	2026-09-09 03:59:34	f	2026-09-09 03:59:34	2026-09-09 03:59:34	\N	\N	0	\N	\N
130	TD-1130	29	ty-1788915607758@example.com	512345678	PAID	3200	0	0	3200	417	a5e98973c44b7d9eed5f1f20265730b5a89eb5ca99ca30a78cedfe70df79cbd8	\N	\N	2026-09-09 04:00:07	2026-09-09 04:00:10	2026-09-09 04:00:08	f	2026-09-09 04:00:07	2026-09-09 04:00:10	\N	\N	0	\N	\N
131	TD-1131	30	rm-1788915868@example.com	512345678	AWAITING_PAYMENT	3200	0	0	3200	417	b8946cf63dc1cea2c4813ee902fc09570d5410ec88de75e14edd65c1e1e18e7d	\N	\N	2026-09-07 22:04:29	2026-09-09 04:04:29	\N	t	2026-09-09 04:04:28	\N	\N	\N	2	2026-09-09 04:04:29	\N
132	TD-1132	31	ty-1788917183419@example.com	512345678	PAID	3200	0	0	3200	0	935c9afa12c3fee0f16df4c0b2e1e56c843db52a65bf5a9212f17b6e5c90e6b8	\N	\N	2026-09-09 04:26:23	2026-09-09 04:26:26	2026-09-09 04:26:24	f	2026-09-09 04:26:23	2026-09-09 04:26:26	\N	\N	0	\N	\N
133	TD-1133	32	ty-1788918666196@example.com	512345678	PAID	3200	0	0	3200	0	f0ba4513b412855533a212af53ed821c631c5ba258242e3cc13099f44fb30e9c	\N	\N	2026-09-09 04:51:06	2026-09-09 04:51:08	2026-09-09 04:51:07	f	2026-09-09 04:51:06	2026-09-09 04:51:08	\N	\N	0	\N	\N
134	TD-1134	35	ty-1788919105742@example.com	512345678	PAID	3200	0	0	3200	0	5c8277d1ac8f3bb9d7f3aef0b0b190b22ae3604c8ebad7d2c0ca0a8064aa6ec1	\N	\N	2026-09-09 04:58:25	2026-09-09 04:58:28	2026-09-09 04:58:27	f	2026-09-09 04:58:25	2026-09-09 04:58:28	\N	\N	0	\N	\N
135	TD-1135	37	ty-1789003527149@example.com	512345678	PAID	3200	0	0	3200	0	6c0935028267d9eb02d87738ff5e6fea221beb840bfecd79b459ca65ea498397	\N	\N	2026-09-10 04:25:27	2026-09-10 04:25:29	2026-09-10 04:25:28	f	2026-09-10 04:25:27	2026-09-10 04:25:29	\N	\N	0	\N	\N
136	TD-1136	42	ty-1789003748151@example.com	512345678	PAID	3200	0	0	3200	0	6f62a7c4b95550ac0ea538ba5535154da6fd2d42d4244cd4a662e18973eac372	\N	\N	2026-09-10 04:29:08	2026-09-10 04:29:10	2026-09-10 04:29:09	f	2026-09-10 04:29:08	2026-09-10 04:29:10	\N	\N	0	\N	\N
137	TD-1137	44	ty-1789004105645@example.com	512345678	PAID	3200	0	0	3200	0	db6672a32eb6b0b74d190899b4d6a312b5eafce31ff5631b09f7e323ee6ea4ba	\N	\N	2026-09-10 04:35:05	2026-09-10 04:35:08	2026-09-10 04:35:07	f	2026-09-10 04:35:05	2026-09-10 04:35:08	\N	\N	0	\N	\N
138	TD-1138	46	ty-1789005113487@example.com	512345678	PAID	3200	0	0	3200	0	3abd27d8507e15e3c3b955775ac597307af00a92eb43cf8775b39c115fd744a3	\N	\N	2026-09-10 04:51:53	2026-09-10 04:51:55	2026-09-10 04:51:54	f	2026-09-10 04:51:53	2026-09-10 04:51:55	\N	\N	0	\N	\N
139	TD-1139	48	ty-1789005598011@example.com	512345678	PAID	3200	0	0	3200	0	840e986929e79a77e4c50de79fecfbce94741f92193ec4f635b891463bdef4c1	\N	\N	2026-09-10 04:59:58	2026-09-10 05:00:00	2026-09-10 04:59:59	f	2026-09-10 04:59:58	2026-09-10 05:00:00	\N	\N	0	\N	\N
140	TD-1140	50	ty-1789005698745@example.com	512345678	PAID	3200	0	0	3200	0	32f4cdae6797a6f14a2a7bbeb4863a14319eb99c4f95aa95c11de7471e0fc7a4	\N	\N	2026-09-10 05:01:38	2026-09-10 05:01:44	2026-09-10 05:01:42	f	2026-09-10 05:01:38	2026-09-10 05:01:44	\N	\N	0	\N	\N
141	TD-1141	52	ty-1789005849804@example.com	512345678	PAID	3200	0	0	3200	0	b1a5ee4d24f87b982f50a2e33e4ea50e4b766d06ecfdd145552e5ae409f63abf	\N	\N	2026-09-10 05:04:09	2026-09-10 05:04:12	2026-09-10 05:04:11	f	2026-09-10 05:04:09	2026-09-10 05:04:12	\N	\N	0	\N	\N
142	TD-1142	54	ty-1789006072468@example.com	512345678	PAID	3200	0	0	3200	0	f75dce8d73923333ab5a3fffcefa3101384258a15ad5e5b1032e5d13c584b345	\N	\N	2026-09-10 05:07:52	2026-09-10 05:07:56	2026-09-10 05:07:54	f	2026-09-10 05:07:52	2026-09-10 05:07:56	\N	\N	0	\N	\N
143	TD-1143	56	ty-1789006182300@example.com	512345678	PAID	3200	0	0	3200	0	4b5a3444e52d8b5b948f59b1697f17db3d127fae9a5c132cc89b93a5132bfff2	\N	\N	2026-09-10 05:09:42	2026-09-10 05:09:45	2026-09-10 05:09:43	f	2026-09-10 05:09:42	2026-09-10 05:09:45	\N	\N	0	\N	\N
144	TD-1144	58	ty-1789006291385@example.com	512345678	PAID	3200	0	0	3200	0	ce16122dc7fee2135e961f88dc5440bec0b992fce856267501a916fced5169e3	\N	\N	2026-09-10 05:11:31	2026-09-10 05:11:34	2026-09-10 05:11:32	f	2026-09-10 05:11:31	2026-09-10 05:11:34	\N	\N	0	\N	\N
145	TD-1145	60	ty-1789006906085@example.com	512345678	PAID	3200	0	0	3200	0	d656ab22799d665258e8d886745e508acaa54207d5396fb47d99c31de08051b4	\N	\N	2026-09-10 05:21:46	2026-09-10 05:21:49	2026-09-10 05:21:47	f	2026-09-10 05:21:46	2026-09-10 05:21:49	\N	\N	0	\N	2026-09-10 05:21:49
146	TD-1146	62	ty-1789007627465@example.com	512345678	PAID	3200	0	0	3200	0	ba74f251fc74a5af51210f83615eff1de8ad24117cc317d382bd9d001b73ab6b	\N	\N	2026-09-10 05:33:47	2026-09-10 05:33:50	2026-09-10 05:33:48	f	2026-09-10 05:33:47	2026-09-10 05:33:50	\N	\N	0	\N	2026-09-10 05:33:50
148	TD-1148	65	ty-1789008874712@example.com	512345678	PAID	3200	0	0	3200	0	be6083a6aa225dd22d077a2668796ce08ef93021f875c11e25319f68b8b27b75	\N	\N	2026-09-10 05:54:34	2026-09-10 05:54:37	2026-09-10 05:54:36	f	2026-09-10 05:54:34	2026-09-10 05:54:37	\N	\N	0	\N	2026-09-10 05:54:37
150	TD-1150	69	ty-1789009418127@example.com	512345678	PAID	3200	0	0	3200	0	b5ec5c10fe0fd92b092d000ef9800fd87c0ba8c81b2ad8f518a2de5f025ad963	\N	\N	2026-09-10 06:03:38	2026-09-10 06:03:40	2026-09-10 06:03:39	f	2026-09-10 06:03:38	2026-09-10 06:03:40	\N	\N	0	\N	2026-09-10 06:03:40
149	TD-1149	67	ty-1789008970378@example.com	512345678	PAID	3200	0	0	3200	0	7e351a2da66b449ab542db01ea700bc435d513d2bae451e428122d6a1b1db3ee	\N	\N	2026-09-10 05:56:10	2026-09-10 05:56:13	2026-09-10 05:56:11	f	2026-09-10 05:56:10	2026-09-10 05:56:13	\N	\N	0	\N	2026-09-10 05:56:13
151	TD-1151	71	ty-1789009512162@example.com	512345678	PAID	3200	0	0	3200	0	3b51c217ce093cf89abf0f4e1f3f2b7db7da09a8508e2fbac6bf375d654c4eab	\N	\N	2026-09-10 06:05:12	2026-09-10 06:05:14	2026-09-10 06:05:13	f	2026-09-10 06:05:12	2026-09-10 06:05:14	\N	\N	0	\N	2026-09-10 06:05:14
152	TD-1152	73	ty-1789009595370@example.com	512345678	PAID	3200	0	0	3200	0	795bbb46d9a1a39d97d0a532c196855cf7716bd5def48aee19cca6f7f601fbd3	\N	\N	2026-09-10 06:06:35	2026-09-10 06:06:37	2026-09-10 06:06:36	f	2026-09-10 06:06:35	2026-09-10 06:06:37	\N	\N	0	\N	2026-09-10 06:06:37
147	TD-1147	63	ssr-1789008457@example.com	512345678	PAID	3200	0	0	3200	0	26a816a17fc168d93c4e43cbccc9461a5b0361e408a76b0a8762613a04b84d39	\N	\N	2026-09-10 05:47:37	2026-09-10 09:59:33	2026-09-10 05:47:37	f	2026-09-10 05:47:37	2026-09-10 09:59:33	\N	\N	0	\N	\N
153	TD-1153	74	noura@example.com	512345678	AWAITING_PAYMENT	30000	5000	0	25000	0	1b2a3fe12eee052cbe7161714d97bc89d7088e1df797eeb26dac197804ab8591	مدارس النخبة الأهلية	310123456789013	2026-09-11 00:39:33	2026-09-11 00:39:33	\N	f	2026-09-11 00:39:33	\N	\N	\N	0	\N	\N
\.


--
-- Data for Name: outbox_events; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.outbox_events (id, aggregate_type, aggregate_id, event_type, payload, correlation_id, idempotency_key, status, attempts, next_retry_at, processed_at, created_at, updated_at) FROM stdin;
01a08b1e-0c6f-700d-9e6d-29bd94c331ea	design_hero	1	hero.published	{"hero_key":"homepage.main","target_type":"homepage","target_ref":null,"version":2}	\N	hero:1:published:2	PENDING	0	2026-09-10 14:39:57	\N	2026-09-10 14:39:57	2026-09-10 14:39:57
01a06a5d-3517-70aa-8c42-7cf2c471c732	review	76	review.submitted	{"reviewId":76,"productId":16,"rating":4}	\N	review.submitted:76	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3560-70de-a1b8-7296c4552373	review	80	review.submitted	{"reviewId":80,"productId":18,"rating":5}	\N	review.submitted:80	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3734-71e3-8b9d-5c3402a04eb6	review	99	review.approved	{"reviewId":99,"productId":22,"rating":5}	\N	review.approved:99	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:06	2026-09-04 06:01:29	2026-09-09 03:41:06
01a06a5d-37b9-71d2-8eb8-1a5052be973a	review	105	review.approved	{"reviewId":105,"productId":24,"rating":5}	\N	review.approved:105	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:06	2026-09-04 06:01:29	2026-09-09 03:41:06
01a083a4-fc2a-73b1-95c4-0fe2c2dfe177	entitlement	3	entitlement.granted	{"entitlement_id":3,"order_id":127}	\N	entitlement:3:granted	PROCESSED	0	2026-09-09 03:50:22	2026-09-09 03:50:23	2026-09-09 03:50:22	2026-09-09 03:50:23
01a083a4-fc2e-722f-a4dc-b0ce255f4589	order	127	order.paid	{"order_id":127,"number":"TD-1127","total_minor":3200}	\N	order:127:paid	PROCESSED	0	2026-09-09 03:50:22	2026-09-09 03:50:23	2026-09-09 03:50:22	2026-09-09 03:50:23
01a083ad-6ac0-7194-a4dc-4b3bc81c0e79	entitlement	5	entitlement.revoked	{"entitlement_id":5,"policy":"immediate","reason":"\\u0645\\u0644\\u0627\\u062d\\u0638\\u0629 \\u062f\\u0627\\u062e\\u0644\\u064a\\u0629 \\u0644\\u0644\\u0645\\u0634\\u0631\\u0641"}	\N	entitlement:5:revoked	PROCESSED	0	2026-09-09 03:59:35	2026-09-09 03:59:35	2026-09-09 03:59:35	2026-09-09 03:59:35
01a083ad-6ac7-71ec-b3e4-497db36917a5	refund	1	refund.completed	{"refund_id":1,"order_id":129,"amount_minor":3200,"policy":"immediate"}	\N	refund:1:completed	PROCESSED	0	2026-09-09 03:59:35	2026-09-09 03:59:35	2026-09-09 03:59:35	2026-09-09 03:59:35
01a06e19-f6b3-71b8-9f1a-0a33fc281815	studio_page	4	studio.page.deleted	{"pageKey":"e2e-publish-check","locale":"ar"}	\N	studio-page:4:deleted	PROCESSED	0	2026-09-04 23:26:30	2026-09-09 03:41:45	2026-09-04 23:26:30	2026-09-09 03:41:45
01a06e1a-ad73-732d-9158-eb90e9ad0e40	studio_page	5	studio.page.deleted	{"pageKey":"e2e-publish-check","locale":"ar"}	\N	studio-page:5:deleted	PROCESSED	0	2026-09-04 23:27:17	2026-09-09 03:41:45	2026-09-04 23:27:17	2026-09-09 03:41:45
01a06e1b-f4e6-7089-927f-4adeacbc23ca	studio_page	6	studio.page.published	{"pageKey":"e2e-publish-check","locale":"ar","version":2}	\N	studio-page:6:published:2	PROCESSED	0	2026-09-04 23:28:41	2026-09-09 03:41:45	2026-09-04 23:28:41	2026-09-09 03:41:45
01a06e1c-acd1-739e-a66e-b368b25c9eed	studio_page	6	studio.page.deleted	{"pageKey":"e2e-publish-check","locale":"ar"}	\N	studio-page:6:deleted	PROCESSED	0	2026-09-04 23:29:28	2026-09-09 03:41:45	2026-09-04 23:29:28	2026-09-09 03:41:45
01a06e1c-ac94-7259-a85c-45aff91d6130	studio_page	6	studio.page.unpublished	{"pageKey":"e2e-publish-check","locale":"ar"}	\N	studio-page:6:unpublished:2	PROCESSED	0	2026-09-04 23:29:28	2026-09-09 03:41:45	2026-09-04 23:29:28	2026-09-09 03:41:45
01a06e53-0a70-7138-b443-6e56fd5edfde	studio_page	7	studio.page.published	{"pageKey":"disclosure-check","locale":"ar","version":2}	\N	studio-page:7:published:2	PROCESSED	0	2026-09-05 00:28:51	2026-09-09 03:41:45	2026-09-05 00:28:51	2026-09-09 03:41:45
01a06e55-7819-704a-8894-57ac686520de	studio_page	7	studio.page.unpublished	{"pageKey":"disclosure-check","locale":"ar"}	\N	studio-page:7:unpublished:2	PROCESSED	0	2026-09-05 00:31:30	2026-09-09 03:41:45	2026-09-05 00:31:30	2026-09-09 03:41:45
01a06e55-784e-7035-a990-fd336a4baee5	studio_page	7	studio.page.deleted	{"pageKey":"disclosure-check","locale":"ar"}	\N	studio-page:7:deleted	PROCESSED	0	2026-09-05 00:31:30	2026-09-09 03:41:45	2026-09-05 00:31:30	2026-09-09 03:41:45
01a0835c-0967-73dc-9970-23ad51dfae47	entitlement	1	entitlement.granted	{"entitlement_id":1,"order_id":124}	\N	entitlement:1:granted	PROCESSED	0	2026-09-09 02:30:42	2026-09-09 03:41:45	2026-09-09 02:30:42	2026-09-09 03:41:45
01a0835c-096d-720c-af6d-4329be9734f5	order	124	order.paid	{"order_id":124,"number":"TD-1124","total_minor":3200}	\N	order:124:paid	PROCESSED	0	2026-09-09 02:30:42	2026-09-09 03:41:45	2026-09-09 02:30:42	2026-09-09 03:41:45
01a0835c-7bf5-7280-8de6-7ee6af6d6795	entitlement	2	entitlement.granted	{"entitlement_id":2,"order_id":125}	\N	entitlement:2:granted	PROCESSED	0	2026-09-09 02:31:11	2026-09-09 03:41:45	2026-09-09 02:31:11	2026-09-09 03:41:45
01a0839c-7df9-71df-9945-eae6abf893bf	order	126	payment.failed	{"order_id":126,"charge_id":"chg_local_1","status":"DECLINED","code":null}	\N	order:126:payment_failed:chg_local_1	PROCESSED	0	2026-09-09 03:41:06	2026-09-09 03:41:45	2026-09-09 03:41:06	2026-09-09 03:41:45
01a083e3-4e10-72f8-924a-0b2bf84ff9ed	order	134	order.paid	{"order_id":134,"number":"TD-1134","total_minor":3200}	\N	order:134:paid	PROCESSED	0	2026-09-09 04:58:27	2026-09-10 09:59:32	2026-09-09 04:58:27	2026-09-10 09:59:32
01a08903-adc2-721b-b5ce-1a67073ba294	entitlement	13	entitlement.granted	{"entitlement_id":13,"order_id":138}	\N	entitlement:13:granted	PROCESSED	0	2026-09-10 04:51:54	2026-09-10 09:59:32	2026-09-10 04:51:54	2026-09-10 09:59:32
01a08903-adc6-716f-8478-3b61cb5e68fa	order	138	order.paid	{"order_id":138,"number":"TD-1138","total_minor":3200}	\N	order:138:paid	PROCESSED	0	2026-09-10 04:51:54	2026-09-10 09:59:32	2026-09-10 04:51:54	2026-09-10 09:59:32
01a08912-51a7-7335-a557-21b4d17c03f7	entitlement	17	entitlement.granted	{"entitlement_id":17,"order_id":142}	\N	entitlement:17:granted	PROCESSED	0	2026-09-10 05:07:54	2026-09-10 09:59:32	2026-09-10 05:07:54	2026-09-10 09:59:32
01a08912-51ac-7048-bf84-a092bb3c1a2d	order	142	order.paid	{"order_id":142,"number":"TD-1142","total_minor":3200}	\N	order:142:paid	PROCESSED	0	2026-09-10 05:07:54	2026-09-10 09:59:32	2026-09-10 05:07:54	2026-09-10 09:59:32
01a0892a-0a60-71b7-a247-502b4f2f12ac	entitlement	21	entitlement.granted	{"entitlement_id":21,"order_id":146}	\N	entitlement:21:granted	PROCESSED	0	2026-09-10 05:33:48	2026-09-10 09:59:32	2026-09-10 05:33:48	2026-09-10 09:59:32
01a0892a-0a63-7160-b3da-938b81fc6637	order	146	order.paid	{"order_id":146,"number":"TD-1146","total_minor":3200}	\N	order:146:paid	PROCESSED	0	2026-09-10 05:33:48	2026-09-10 09:59:32	2026-09-10 05:33:48	2026-09-10 09:59:32
01a08945-5e31-72d7-8e98-bef2ac468e7a	entitlement	25	entitlement.granted	{"entitlement_id":25,"order_id":150}	\N	entitlement:25:granted	PROCESSED	0	2026-09-10 06:03:39	2026-09-10 09:59:33	2026-09-10 06:03:39	2026-09-10 09:59:33
01a08945-5e35-711b-b5e1-fc47c521f1d3	order	150	order.paid	{"order_id":150,"number":"TD-1150","total_minor":3200}	\N	order:150:paid	PROCESSED	0	2026-09-10 06:03:39	2026-09-10 09:59:33	2026-09-10 06:03:39	2026-09-10 09:59:33
01a08c60-649a-710a-a694-dafa8e20d8f1	design_hero	1	hero.published	{"hero_key":"homepage.main","target_type":"homepage","target_ref":null,"version":3}	\N	hero:1:published:3	PENDING	0	2026-09-10 20:32:02	\N	2026-09-10 20:32:02	2026-09-10 20:32:02
01a06a5d-33ca-70c7-9729-e51a307e314c	review	61	review.approved	{"reviewId":61,"productId":12,"rating":4}	\N	review.approved:61	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-33e3-7386-ab19-a85dd9d681e1	review	62	review.submitted	{"reviewId":62,"productId":12,"rating":2}	\N	review.submitted:62	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-33e7-724e-92dd-76cde5c8e8c3	review	62	review.approved	{"reviewId":62,"productId":12,"rating":2}	\N	review.approved:62	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3409-72a0-9461-7b95df606b8a	review	63	review.submitted	{"reviewId":63,"productId":13,"rating":5}	\N	review.submitted:63	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-340d-72ad-9fa6-ca01cec39e5e	review	63	review.approved	{"reviewId":63,"productId":13,"rating":5}	\N	review.approved:63	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-341d-70d6-8fe4-639905580384	review	64	review.submitted	{"reviewId":64,"productId":13,"rating":4}	\N	review.submitted:64	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3421-70a4-8adc-acb656411efb	review	64	review.approved	{"reviewId":64,"productId":13,"rating":4}	\N	review.approved:64	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-342e-71a6-bae3-a3a447f9c585	review	65	review.submitted	{"reviewId":65,"productId":13,"rating":5}	\N	review.submitted:65	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3432-7027-ba88-486617a29d23	review	65	review.approved	{"reviewId":65,"productId":13,"rating":5}	\N	review.approved:65	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3341-732a-9d61-bd5f9b019ef7	review	56	review.approved	{"reviewId":56,"productId":10,"rating":3}	\N	review.approved:56	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3357-700f-b15b-0345cd9af7cb	review	57	review.submitted	{"reviewId":57,"productId":11,"rating":5}	\N	review.submitted:57	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-2f46-70f9-94eb-bb19b6c72392	review	26	review.submitted	{"reviewId":26,"productId":5,"rating":5}	\N	review.submitted:26	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:48	2026-09-04 06:01:26	2026-09-09 03:40:48
01a06a5d-2f4a-72a1-aa41-d015fa286141	review	26	review.approved	{"reviewId":26,"productId":5,"rating":5}	\N	review.approved:26	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:48	2026-09-04 06:01:26	2026-09-09 03:40:48
01a06a5d-2f57-719d-ad0b-96deceab07f4	review	27	review.submitted	{"reviewId":27,"productId":5,"rating":5}	\N	review.submitted:27	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:48	2026-09-04 06:01:26	2026-09-09 03:40:48
01a06a5d-2f6f-738e-a902-3e2fd523d50a	review	27	review.approved	{"reviewId":27,"productId":5,"rating":5}	\N	review.approved:27	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2f90-7381-b0de-a0c4c727cb66	review	28	review.submitted	{"reviewId":28,"productId":5,"rating":4}	\N	review.submitted:28	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-335b-72be-b5c9-52e685294104	review	57	review.approved	{"reviewId":57,"productId":11,"rating":5}	\N	review.approved:57	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-336e-7143-ae6b-c136651c2546	review	58	review.submitted	{"reviewId":58,"productId":12,"rating":2}	\N	review.submitted:58	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3372-734e-875e-1fbbed6e756f	review	58	review.approved	{"reviewId":58,"productId":12,"rating":2}	\N	review.approved:58	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3385-7103-926b-e5b3e5db5afb	review	59	review.submitted	{"reviewId":59,"productId":12,"rating":3}	\N	review.submitted:59	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-338a-70c8-b5bc-0835eb6aae9d	review	59	review.approved	{"reviewId":59,"productId":12,"rating":3}	\N	review.approved:59	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-339a-72b6-bdbd-e27b063318ba	review	60	review.submitted	{"reviewId":60,"productId":12,"rating":1}	\N	review.submitted:60	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-33c4-72b0-b968-1b7efccd66bb	review	61	review.submitted	{"reviewId":61,"productId":12,"rating":4}	\N	review.submitted:61	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35a1-7217-9b6e-45f228ca372d	review	83	review.approved	{"reviewId":83,"productId":18,"rating":5}	\N	review.approved:83	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35aa-7205-a4cf-4ab1020ffde5	review	84	review.submitted	{"reviewId":84,"productId":18,"rating":2}	\N	review.submitted:84	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35af-7340-9574-61974cd93b87	review	84	review.approved	{"reviewId":84,"productId":18,"rating":2}	\N	review.approved:84	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35bf-714c-96be-e5fb6d982485	review	85	review.submitted	{"reviewId":85,"productId":18,"rating":4}	\N	review.submitted:85	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35c4-73ff-8d79-51ae83465bd9	review	85	review.approved	{"reviewId":85,"productId":18,"rating":4}	\N	review.approved:85	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35d2-71df-8b14-197e9b3ddabc	review	86	review.submitted	{"reviewId":86,"productId":18,"rating":5}	\N	review.submitted:86	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35d6-723d-873c-e9a58197ba6d	review	86	review.approved	{"reviewId":86,"productId":18,"rating":5}	\N	review.approved:86	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35fa-725e-95bb-d859e9aafd8d	review	87	review.submitted	{"reviewId":87,"productId":19,"rating":5}	\N	review.submitted:87	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-35fe-72fe-a15c-7ea4a87126ff	review	87	review.approved	{"reviewId":87,"productId":19,"rating":5}	\N	review.approved:87	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-360b-7252-afea-c421609e9694	review	88	review.submitted	{"reviewId":88,"productId":19,"rating":5}	\N	review.submitted:88	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-360e-7126-b817-2f61b8107557	review	88	review.approved	{"reviewId":88,"productId":19,"rating":5}	\N	review.approved:88	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-361b-7067-8ea0-5bcfd6dedc9d	review	89	review.submitted	{"reviewId":89,"productId":19,"rating":5}	\N	review.submitted:89	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3620-73f6-bbb2-21b1cfdcc25a	review	89	review.approved	{"reviewId":89,"productId":19,"rating":5}	\N	review.approved:89	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3635-70cb-9237-9455d98b3224	review	90	review.submitted	{"reviewId":90,"productId":21,"rating":4}	\N	review.submitted:90	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-364c-700a-abd1-dd67b01d49ae	review	91	review.submitted	{"reviewId":91,"productId":21,"rating":4}	\N	review.submitted:91	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3652-7287-920a-09a1a55c9f05	review	91	review.approved	{"reviewId":91,"productId":21,"rating":4}	\N	review.approved:91	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-366a-721a-8f59-b5be8d00d86e	review	92	review.submitted	{"reviewId":92,"productId":21,"rating":5}	\N	review.submitted:92	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-366e-70b3-b40d-5c91605d3db4	review	92	review.approved	{"reviewId":92,"productId":21,"rating":5}	\N	review.approved:92	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a083a5-4d08-73bd-83b8-4b8d4627ed8f	order	128	order.paid	{"order_id":128,"number":"TD-1128","total_minor":3200}	\N	order:128:paid	PROCESSED	0	2026-09-09 03:50:43	2026-09-09 03:59:34	2026-09-09 03:50:43	2026-09-09 03:59:34
01a083ad-ed58-71ae-9c82-20f2626f29f5	entitlement	6	entitlement.granted	{"entitlement_id":6,"order_id":130}	\N	entitlement:6:granted	PROCESSED	0	2026-09-09 04:00:08	2026-09-10 09:59:32	2026-09-09 04:00:08	2026-09-10 09:59:32
01a083ad-ed5a-70a4-b283-aa73ed148602	order	130	order.paid	{"order_id":130,"number":"TD-1130","total_minor":3200}	\N	order:130:paid	PROCESSED	0	2026-09-09 04:00:08	2026-09-10 09:59:32	2026-09-09 04:00:08	2026-09-10 09:59:32
01a088eb-792e-70ed-b261-72eae74f055a	entitlement	10	entitlement.granted	{"entitlement_id":10,"order_id":135}	\N	entitlement:10:granted	PROCESSED	0	2026-09-10 04:25:28	2026-09-10 09:59:32	2026-09-10 04:25:28	2026-09-10 09:59:32
01a088eb-7932-72f7-9ecc-346456f40b3b	order	135	order.paid	{"order_id":135,"number":"TD-1135","total_minor":3200}	\N	order:135:paid	PROCESSED	0	2026-09-10 04:25:28	2026-09-10 09:59:32	2026-09-10 04:25:28	2026-09-10 09:59:32
01a0890b-132d-726e-aba4-be7828d95d2a	entitlement	14	entitlement.granted	{"entitlement_id":14,"order_id":139}	\N	entitlement:14:granted	PROCESSED	0	2026-09-10 04:59:59	2026-09-10 09:59:32	2026-09-10 04:59:59	2026-09-10 09:59:32
01a0890b-1331-7305-802b-5789f9babfe5	order	139	order.paid	{"order_id":139,"number":"TD-1139","total_minor":3200}	\N	order:139:paid	PROCESSED	0	2026-09-10 04:59:59	2026-09-10 09:59:32	2026-09-10 04:59:59	2026-09-10 09:59:32
01a08913-fd37-734e-bcf0-91ed6391ef49	entitlement	18	entitlement.granted	{"entitlement_id":18,"order_id":143}	\N	entitlement:18:granted	PROCESSED	0	2026-09-10 05:09:43	2026-09-10 09:59:32	2026-09-10 05:09:43	2026-09-10 09:59:32
01a08913-fd38-70e9-af28-6a44c3f3e7f6	order	143	order.paid	{"order_id":143,"number":"TD-1143","total_minor":3200}	\N	order:143:paid	PROCESSED	0	2026-09-10 05:09:43	2026-09-10 09:59:32	2026-09-10 05:09:43	2026-09-10 09:59:32
01a08936-adf6-7377-befd-db529f57cde9	entitlement	22	entitlement.granted	{"entitlement_id":22,"order_id":147}	\N	entitlement:22:granted	PROCESSED	0	2026-09-10 05:47:37	2026-09-10 09:59:32	2026-09-10 05:47:37	2026-09-10 09:59:32
01a08936-adfa-7320-ba1c-0c15b3244593	order	147	order.paid	{"order_id":147,"number":"TD-1147","total_minor":3200}	\N	order:147:paid	PROCESSED	0	2026-09-10 05:47:37	2026-09-10 09:59:33	2026-09-10 05:47:37	2026-09-10 09:59:33
01a08946-cadc-73e7-84aa-0a2ba85ef759	entitlement	26	entitlement.granted	{"entitlement_id":26,"order_id":151}	\N	entitlement:26:granted	PROCESSED	0	2026-09-10 06:05:13	2026-09-10 09:59:33	2026-09-10 06:05:13	2026-09-10 09:59:33
01a08946-cadf-7252-9dc7-5bda23e87c85	order	151	order.paid	{"order_id":151,"number":"TD-1151","total_minor":3200}	\N	order:151:paid	PROCESSED	0	2026-09-10 06:05:13	2026-09-10 09:59:33	2026-09-10 06:05:13	2026-09-10 09:59:33
01a06a5d-3308-73dc-82c1-c2dd89528223	review	54	review.approved	{"reviewId":54,"productId":10,"rating":4}	\N	review.approved:54	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-331e-737b-9498-8538326fb191	review	55	review.submitted	{"reviewId":55,"productId":10,"rating":5}	\N	review.submitted:55	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-3324-7205-8160-66f4ad9402fc	review	55	review.approved	{"reviewId":55,"productId":10,"rating":5}	\N	review.approved:55	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-333e-72d2-8023-4b65da493568	review	56	review.submitted	{"reviewId":56,"productId":10,"rating":3}	\N	review.submitted:56	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32a5-72d6-8115-18bffe08e26b	review	51	review.submitted	{"reviewId":51,"productId":9,"rating":5}	\N	review.submitted:51	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32b0-716d-8355-233b59a67208	review	51	review.approved	{"reviewId":51,"productId":9,"rating":5}	\N	review.approved:51	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32c2-7246-b54e-5170bd91f7d6	review	52	review.submitted	{"reviewId":52,"productId":9,"rating":4}	\N	review.submitted:52	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32c7-73de-ae00-f8c76167fe00	review	52	review.approved	{"reviewId":52,"productId":9,"rating":4}	\N	review.approved:52	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32ef-72d5-aa23-1930085867b8	review	53	review.submitted	{"reviewId":53,"productId":10,"rating":4}	\N	review.submitted:53	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-32f4-7095-b57a-859088dacfc3	review	53	review.approved	{"reviewId":53,"productId":10,"rating":4}	\N	review.approved:53	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-3448-72cd-a820-d9da821a7973	review	66	review.submitted	{"reviewId":66,"productId":13,"rating":4}	\N	review.submitted:66	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-344d-7263-adc2-869b60490143	review	66	review.approved	{"reviewId":66,"productId":13,"rating":4}	\N	review.approved:66	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-345d-73f4-89ba-b7e46d666953	review	67	review.submitted	{"reviewId":67,"productId":13,"rating":5}	\N	review.submitted:67	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3461-70bb-8f46-079787547f4b	review	67	review.approved	{"reviewId":67,"productId":13,"rating":5}	\N	review.approved:67	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-346d-729c-823a-0529712f9546	review	68	review.submitted	{"reviewId":68,"productId":13,"rating":5}	\N	review.submitted:68	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a083ad-6753-7151-9dc0-e55f17322b11	entitlement	5	entitlement.granted	{"entitlement_id":5,"order_id":129}	\N	entitlement:5:granted	PROCESSED	0	2026-09-09 03:59:34	2026-09-09 03:59:34	2026-09-09 03:59:34	2026-09-09 03:59:34
01a083ad-6756-7377-9139-be0398575e2d	order	129	order.paid	{"order_id":129,"number":"TD-1129","total_minor":3200}	\N	order:129:paid	PROCESSED	0	2026-09-09 03:59:34	2026-09-09 03:59:34	2026-09-09 03:59:34	2026-09-09 03:59:34
01a083c5-f95f-707c-bdf3-3d4c50343f94	entitlement	7	entitlement.granted	{"entitlement_id":7,"order_id":132}	\N	entitlement:7:granted	PROCESSED	0	2026-09-09 04:26:24	2026-09-10 09:59:32	2026-09-09 04:26:24	2026-09-10 09:59:32
01a083c5-f966-72b3-8aa2-591e3687038c	order	132	order.paid	{"order_id":132,"number":"TD-1132","total_minor":3200}	\N	order:132:paid	PROCESSED	0	2026-09-09 04:26:24	2026-09-10 09:59:32	2026-09-09 04:26:24	2026-09-10 09:59:32
01a088ee-d7af-7093-b87b-32d01851f9bc	entitlement	11	entitlement.granted	{"entitlement_id":11,"order_id":136}	\N	entitlement:11:granted	PROCESSED	0	2026-09-10 04:29:09	2026-09-10 09:59:32	2026-09-10 04:29:09	2026-09-10 09:59:32
01a088ee-d7b3-70fb-b3d8-89f488331952	order	136	order.paid	{"order_id":136,"number":"TD-1136","total_minor":3200}	\N	order:136:paid	PROCESSED	0	2026-09-10 04:29:09	2026-09-10 09:59:32	2026-09-10 04:29:09	2026-09-10 09:59:32
01a0890c-a38a-71b4-b2eb-dc0d6a274399	entitlement	15	entitlement.granted	{"entitlement_id":15,"order_id":140}	\N	entitlement:15:granted	PROCESSED	0	2026-09-10 05:01:42	2026-09-10 09:59:32	2026-09-10 05:01:42	2026-09-10 09:59:32
01a0890c-a38c-71a2-80ad-9a9e191a56e3	order	140	order.paid	{"order_id":140,"number":"TD-1140","total_minor":3200}	\N	order:140:paid	PROCESSED	0	2026-09-10 05:01:42	2026-09-10 09:59:32	2026-09-10 05:01:42	2026-09-10 09:59:32
01a08915-a6d9-73fc-b587-833e5509ea01	entitlement	19	entitlement.granted	{"entitlement_id":19,"order_id":144}	\N	entitlement:19:granted	PROCESSED	0	2026-09-10 05:11:32	2026-09-10 09:59:32	2026-09-10 05:11:32	2026-09-10 09:59:32
01a08915-a6db-73f5-8352-cd6076e806ff	order	144	order.paid	{"order_id":144,"number":"TD-1144","total_minor":3200}	\N	order:144:paid	PROCESSED	0	2026-09-10 05:11:32	2026-09-10 09:59:32	2026-09-10 05:11:32	2026-09-10 09:59:32
01a0893d-120c-7003-920a-bc2c3d0e90b9	entitlement	23	entitlement.granted	{"entitlement_id":23,"order_id":148}	\N	entitlement:23:granted	PROCESSED	0	2026-09-10 05:54:36	2026-09-10 09:59:33	2026-09-10 05:54:36	2026-09-10 09:59:33
01a0893d-120f-730b-8eb9-63431412f79e	order	148	order.paid	{"order_id":148,"number":"TD-1148","total_minor":3200}	\N	order:148:paid	PROCESSED	0	2026-09-10 05:54:36	2026-09-10 09:59:33	2026-09-10 05:54:36	2026-09-10 09:59:33
01a08948-0fd9-71df-9231-90210b2e5a2a	entitlement	27	entitlement.granted	{"entitlement_id":27,"order_id":152}	\N	entitlement:27:granted	PROCESSED	0	2026-09-10 06:06:36	2026-09-10 09:59:33	2026-09-10 06:06:36	2026-09-10 09:59:33
01a08948-0fda-7264-9676-d7126a7992d0	order	152	order.paid	{"order_id":152,"number":"TD-1152","total_minor":3200}	\N	order:152:paid	PROCESSED	0	2026-09-10 06:06:36	2026-09-10 09:59:33	2026-09-10 06:06:36	2026-09-10 09:59:33
01a06a5d-34f5-7347-aa14-c61efed98bae	review	74	review.approved	{"reviewId":74,"productId":16,"rating":5}	\N	review.approved:74	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3472-7036-ae05-efeabcc4d956	review	68	review.approved	{"reviewId":68,"productId":13,"rating":5}	\N	review.approved:68	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3486-706c-adeb-df44392df0e7	review	69	review.submitted	{"reviewId":69,"productId":13,"rating":3}	\N	review.submitted:69	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-348b-715d-83ff-6f12d288e519	review	69	review.approved	{"reviewId":69,"productId":13,"rating":3}	\N	review.approved:69	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-349e-719f-b2bd-79a0e2b8963f	review	70	review.submitted	{"reviewId":70,"productId":15,"rating":4}	\N	review.submitted:70	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34a3-7345-82f6-1ee90d88ab66	review	70	review.approved	{"reviewId":70,"productId":15,"rating":4}	\N	review.approved:70	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34b2-702b-91f1-15de1eb9f369	review	71	review.submitted	{"reviewId":71,"productId":15,"rating":5}	\N	review.submitted:71	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34b7-7315-aaeb-c50fe7903bd3	review	71	review.approved	{"reviewId":71,"productId":15,"rating":5}	\N	review.approved:71	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34c6-7097-a2f8-bab153ae4573	review	72	review.submitted	{"reviewId":72,"productId":15,"rating":4}	\N	review.submitted:72	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34ca-7193-a900-3c932f377566	review	72	review.approved	{"reviewId":72,"productId":15,"rating":4}	\N	review.approved:72	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34df-7295-b37d-f394fb34a13d	review	73	review.submitted	{"reviewId":73,"productId":16,"rating":5}	\N	review.submitted:73	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34e4-7048-81ac-a10089f92c58	review	73	review.approved	{"reviewId":73,"productId":16,"rating":5}	\N	review.approved:73	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-34f1-7031-bc2b-9459279c7bf3	review	74	review.submitted	{"reviewId":74,"productId":16,"rating":5}	\N	review.submitted:74	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3500-70d9-a729-29fef75a2ce9	review	75	review.submitted	{"reviewId":75,"productId":16,"rating":4}	\N	review.submitted:75	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3503-733b-96e5-d87395f0e12c	review	75	review.approved	{"reviewId":75,"productId":16,"rating":4}	\N	review.approved:75	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a083a5-4d07-723e-91bd-bee654db2b0b	entitlement	4	entitlement.granted	{"entitlement_id":4,"order_id":128}	\N	entitlement:4:granted	PROCESSED	0	2026-09-09 03:50:43	2026-09-09 03:59:34	2026-09-09 03:50:43	2026-09-09 03:59:34
01a083dc-98bf-73dd-abdb-715afc47a1b3	entitlement	8	entitlement.granted	{"entitlement_id":8,"order_id":133}	\N	entitlement:8:granted	PROCESSED	0	2026-09-09 04:51:07	2026-09-10 09:59:32	2026-09-09 04:51:07	2026-09-10 09:59:32
01a083dc-98c4-7216-9dba-8e39ef6b5252	order	133	order.paid	{"order_id":133,"number":"TD-1133","total_minor":3200}	\N	order:133:paid	PROCESSED	0	2026-09-09 04:51:07	2026-09-10 09:59:32	2026-09-09 04:51:07	2026-09-10 09:59:32
01a083e3-4e0a-7083-a175-5462bbcfcdd9	entitlement	9	entitlement.granted	{"entitlement_id":9,"order_id":134}	\N	entitlement:9:granted	PROCESSED	0	2026-09-09 04:58:27	2026-09-10 09:59:32	2026-09-09 04:58:27	2026-09-10 09:59:32
01a088f4-4d30-72c8-bfda-911276b5387b	entitlement	12	entitlement.granted	{"entitlement_id":12,"order_id":137}	\N	entitlement:12:granted	PROCESSED	0	2026-09-10 04:35:07	2026-09-10 09:59:32	2026-09-10 04:35:07	2026-09-10 09:59:32
01a088f4-4d52-7330-a301-12992b37b3e2	order	137	order.paid	{"order_id":137,"number":"TD-1137","total_minor":3200}	\N	order:137:paid	PROCESSED	0	2026-09-10 04:35:07	2026-09-10 09:59:32	2026-09-10 04:35:07	2026-09-10 09:59:32
01a0890e-eada-707b-85c1-6008a36defe5	entitlement	16	entitlement.granted	{"entitlement_id":16,"order_id":141}	\N	entitlement:16:granted	PROCESSED	0	2026-09-10 05:04:11	2026-09-10 09:59:32	2026-09-10 05:04:11	2026-09-10 09:59:32
01a0890e-eade-70bc-8cbb-21163e11ac70	order	141	order.paid	{"order_id":141,"number":"TD-1141","total_minor":3200}	\N	order:141:paid	PROCESSED	0	2026-09-10 05:04:11	2026-09-10 09:59:32	2026-09-10 05:04:11	2026-09-10 09:59:32
01a0891f-0950-7183-b8bc-a40a6ddac46a	entitlement	20	entitlement.granted	{"entitlement_id":20,"order_id":145}	\N	entitlement:20:granted	PROCESSED	0	2026-09-10 05:21:47	2026-09-10 09:59:32	2026-09-10 05:21:47	2026-09-10 09:59:32
01a0891f-095a-713d-a734-456b7d7d9352	order	145	order.paid	{"order_id":145,"number":"TD-1145","total_minor":3200}	\N	order:145:paid	PROCESSED	0	2026-09-10 05:21:47	2026-09-10 09:59:32	2026-09-10 05:21:47	2026-09-10 09:59:32
01a0893e-872e-7329-9712-9cbe734359e9	entitlement	24	entitlement.granted	{"entitlement_id":24,"order_id":149}	\N	entitlement:24:granted	PROCESSED	0	2026-09-10 05:56:11	2026-09-10 09:59:33	2026-09-10 05:56:11	2026-09-10 09:59:33
01a0893e-8730-72bb-b0ac-dbbb9edd82b0	order	149	order.paid	{"order_id":149,"number":"TD-1149","total_minor":3200}	\N	order:149:paid	PROCESSED	0	2026-09-10 05:56:11	2026-09-10 09:59:33	2026-09-10 05:56:11	2026-09-10 09:59:33
01a08a1d-5343-732e-b2e3-bab2b259577c	studio_page	999	studio.page.published	{"pageKey":"about-us","locale":"ar","version":1}	\N	verify:storefront-cache:1789023572	PROCESSED	0	2026-09-10 09:59:32	2026-09-10 09:59:33	2026-09-10 09:59:32	2026-09-10 09:59:33
01a06a5d-36ae-7225-abe6-be6b4a4cd7ea	review	93	review.approved	{"reviewId":93,"productId":22,"rating":5}	\N	review.approved:93	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36c2-73bb-a46c-88616b40c0dd	review	94	review.submitted	{"reviewId":94,"productId":22,"rating":4}	\N	review.submitted:94	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36c6-7001-a4d1-c3ffdd30af1f	review	94	review.approved	{"reviewId":94,"productId":22,"rating":4}	\N	review.approved:94	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36d4-7030-9f27-047027222e16	review	95	review.submitted	{"reviewId":95,"productId":22,"rating":5}	\N	review.submitted:95	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-379e-738c-8629-74fdec594977	review	104	review.approved	{"reviewId":104,"productId":24,"rating":4}	\N	review.approved:104	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3741-707b-b2d5-c0ec25a82ad6	review	100	review.submitted	{"reviewId":100,"productId":22,"rating":4}	\N	review.submitted:100	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3744-7315-98a8-4fd5897861df	review	100	review.approved	{"reviewId":100,"productId":22,"rating":4}	\N	review.approved:100	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3750-7240-94ca-6b4b27761c84	review	101	review.submitted	{"reviewId":101,"productId":22,"rating":5}	\N	review.submitted:101	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3755-72ff-bb94-4065edfff67f	review	101	review.approved	{"reviewId":101,"productId":22,"rating":5}	\N	review.approved:101	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3778-72fd-ae19-a48e70e3b507	review	102	review.submitted	{"reviewId":102,"productId":24,"rating":3}	\N	review.submitted:102	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-377c-7153-88ca-aab437ee00df	review	102	review.approved	{"reviewId":102,"productId":24,"rating":3}	\N	review.approved:102	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3788-73cc-a9a7-a58f68a494ad	review	103	review.submitted	{"reviewId":103,"productId":24,"rating":4}	\N	review.submitted:103	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-378b-722a-b2fc-7c0cfce801db	review	103	review.approved	{"reviewId":103,"productId":24,"rating":4}	\N	review.approved:103	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06a5d-3799-72f9-a1d4-0ff25a00b0e6	review	104	review.submitted	{"reviewId":104,"productId":24,"rating":4}	\N	review.submitted:104	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:45	2026-09-04 06:01:29	2026-09-09 03:41:45
01a06adf-bbf2-7264-ba0d-7a53e9e73713	studio_page	2	studio.page.deleted	{"pageKey":"tmp-verify","locale":"ar"}	\N	studio-page:2:deleted	PROCESSED	0	2026-09-04 08:24:02	2026-09-09 03:41:45	2026-09-04 08:24:02	2026-09-09 03:41:45
01a06cbb-cbb7-7363-9665-a85f26853569	studio_page	1	studio.page.published	{"pageKey":"g","locale":"ar","version":15}	\N	studio-page:1:published:15	PROCESSED	0	2026-09-04 17:04:01	2026-09-09 03:41:45	2026-09-04 17:04:01	2026-09-09 03:41:45
01a06cf0-eab4-73ae-bf18-a7f879bdc384	studio_page	1	studio.page.published	{"pageKey":"g","locale":"ar","version":21}	\N	studio-page:1:published:21	PROCESSED	0	2026-09-04 18:02:03	2026-09-09 03:41:45	2026-09-04 18:02:03	2026-09-09 03:41:45
01a06cfa-4673-7371-adf6-12ddf0ea0aa5	studio_page	1	studio.page.published	{"pageKey":"g","locale":"ar","version":24}	\N	studio-page:1:published:24	PROCESSED	0	2026-09-04 18:12:16	2026-09-09 03:41:45	2026-09-04 18:12:16	2026-09-09 03:41:45
01a0835c-7bfa-70fe-9ed0-a1336a185343	order	125	order.paid	{"order_id":125,"number":"TD-1125","total_minor":3200}	\N	order:125:paid	PROCESSED	0	2026-09-09 02:31:11	2026-09-09 03:41:45	2026-09-09 02:31:11	2026-09-09 03:41:45
01a06a5d-2c90-73c5-b254-8de842666825	review	1	review.approved	{"reviewId":1,"productId":3,"rating":5}	\N	review.approved:1	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ca4-72b9-832f-16c512296add	review	2	review.submitted	{"reviewId":2,"productId":3,"rating":5}	\N	review.submitted:2	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ca9-73d3-9141-66759e06425e	review	2	review.approved	{"reviewId":2,"productId":3,"rating":5}	\N	review.approved:2	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2cba-72b8-9048-7e949ac52d7e	review	3	review.submitted	{"reviewId":3,"productId":3,"rating":5}	\N	review.submitted:3	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2cbf-737a-b482-7d1f1afcf190	review	3	review.approved	{"reviewId":3,"productId":3,"rating":5}	\N	review.approved:3	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ce1-7334-8c4a-33f539b5333f	review	4	review.approved	{"reviewId":4,"productId":3,"rating":4}	\N	review.approved:4	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2cef-709e-9b6d-7fe29ab2f9e1	review	5	review.submitted	{"reviewId":5,"productId":3,"rating":5}	\N	review.submitted:5	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2cf4-712e-8dc6-3e5df003277a	review	5	review.approved	{"reviewId":5,"productId":3,"rating":5}	\N	review.approved:5	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d0c-7062-946d-dcdac78755ba	review	6	review.submitted	{"reviewId":6,"productId":3,"rating":4}	\N	review.submitted:6	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d10-72ca-8c5d-214451a90001	review	6	review.approved	{"reviewId":6,"productId":3,"rating":4}	\N	review.approved:6	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d25-7335-a6f3-a7f7ca4f92f2	review	7	review.submitted	{"reviewId":7,"productId":3,"rating":5}	\N	review.submitted:7	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d2a-72ba-955d-332d6d3807db	review	7	review.approved	{"reviewId":7,"productId":3,"rating":5}	\N	review.approved:7	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d37-71c2-be0c-9a39071a404b	review	8	review.submitted	{"reviewId":8,"productId":3,"rating":5}	\N	review.submitted:8	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d3c-73aa-abee-6a306f767a74	review	8	review.approved	{"reviewId":8,"productId":3,"rating":5}	\N	review.approved:8	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d48-7160-9e03-85e19fff7281	review	9	review.submitted	{"reviewId":9,"productId":3,"rating":3}	\N	review.submitted:9	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d4b-7103-a583-1f21dc53255c	review	9	review.approved	{"reviewId":9,"productId":3,"rating":3}	\N	review.approved:9	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d61-7087-a68c-6e514cd84cfc	review	10	review.submitted	{"reviewId":10,"productId":3,"rating":5}	\N	review.submitted:10	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d65-706b-94a6-1b94dd30bf51	review	10	review.approved	{"reviewId":10,"productId":3,"rating":5}	\N	review.approved:10	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d73-7300-bb5d-5f81f071cc25	review	11	review.submitted	{"reviewId":11,"productId":3,"rating":4}	\N	review.submitted:11	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d7a-7230-935f-2c94642dc408	review	11	review.approved	{"reviewId":11,"productId":3,"rating":4}	\N	review.approved:11	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d9c-73f7-9361-742e601e7156	review	12	review.approved	{"reviewId":12,"productId":3,"rating":5}	\N	review.approved:12	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2dc0-735d-afb8-b5d42523eb9c	review	13	review.submitted	{"reviewId":13,"productId":4,"rating":5}	\N	review.submitted:13	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2dc6-71d0-8145-a51401cdcdb1	review	13	review.approved	{"reviewId":13,"productId":4,"rating":5}	\N	review.approved:13	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2dd2-71bf-8568-cceb7014617e	review	14	review.submitted	{"reviewId":14,"productId":4,"rating":4}	\N	review.submitted:14	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2dd6-72ca-8fcd-b70df4a0905c	review	14	review.approved	{"reviewId":14,"productId":4,"rating":4}	\N	review.approved:14	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2de2-73b3-8390-1e7a679b6558	review	15	review.submitted	{"reviewId":15,"productId":4,"rating":4}	\N	review.submitted:15	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2de6-715b-9bb7-28c40e502204	review	15	review.approved	{"reviewId":15,"productId":4,"rating":4}	\N	review.approved:15	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2df8-729f-9157-91c8689f978d	review	16	review.submitted	{"reviewId":16,"productId":4,"rating":5}	\N	review.submitted:16	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2dfb-717e-b0c6-9ca66b397963	review	16	review.approved	{"reviewId":16,"productId":4,"rating":5}	\N	review.approved:16	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e28-7015-9e29-e3157ac1a500	review	17	review.submitted	{"reviewId":17,"productId":4,"rating":3}	\N	review.submitted:17	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e35-730f-944f-2fd6e00b3465	review	17	review.approved	{"reviewId":17,"productId":4,"rating":3}	\N	review.approved:17	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e46-701e-a4e5-52b273f02692	review	18	review.submitted	{"reviewId":18,"productId":4,"rating":4}	\N	review.submitted:18	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e4b-72d7-886a-40a0e9e2c632	review	18	review.approved	{"reviewId":18,"productId":4,"rating":4}	\N	review.approved:18	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e64-73f1-bcca-caa87b1d648d	review	19	review.submitted	{"reviewId":19,"productId":4,"rating":4}	\N	review.submitted:19	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2e6b-71e7-be5e-a3b610daad96	review	19	review.approved	{"reviewId":19,"productId":4,"rating":4}	\N	review.approved:19	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2eb6-7146-9b2e-14249dc7f065	review	20	review.approved	{"reviewId":20,"productId":5,"rating":5}	\N	review.approved:20	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ed1-7245-948f-62242daf7982	review	21	review.submitted	{"reviewId":21,"productId":5,"rating":5}	\N	review.submitted:21	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ed6-7016-9799-524dc2aa79ae	review	21	review.approved	{"reviewId":21,"productId":5,"rating":5}	\N	review.approved:21	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ee2-70e9-8701-6a8fb647d509	review	22	review.submitted	{"reviewId":22,"productId":5,"rating":5}	\N	review.submitted:22	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-3304-7077-ab21-0fe880013c35	review	54	review.submitted	{"reviewId":54,"productId":10,"rating":4}	\N	review.submitted:54	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:41:06	2026-09-04 06:01:27	2026-09-09 03:41:06
01a06a5d-33a2-7294-b226-720d73c202b7	review	60	review.approved	{"reviewId":60,"productId":12,"rating":1}	\N	review.approved:60	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3639-7051-a84a-d6b754d1bc05	review	90	review.approved	{"reviewId":90,"productId":21,"rating":4}	\N	review.approved:90	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36a8-70af-aefb-a1e845df518d	review	93	review.submitted	{"reviewId":93,"productId":22,"rating":5}	\N	review.submitted:93	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-2c88-7187-b67d-f49e704e2ff8	review	1	review.submitted	{"reviewId":1,"productId":3,"rating":5}	\N	review.submitted:1	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2cdc-7095-9969-b1590f254fd9	review	4	review.submitted	{"reviewId":4,"productId":3,"rating":4}	\N	review.submitted:4	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2d95-7274-8eda-3251fb024983	review	12	review.submitted	{"reviewId":12,"productId":3,"rating":5}	\N	review.submitted:12	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ea8-7208-a47e-b7eb74b70e73	review	20	review.submitted	{"reviewId":20,"productId":5,"rating":5}	\N	review.submitted:20	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2ee5-70ee-8854-c812b537c83d	review	22	review.approved	{"reviewId":22,"productId":5,"rating":5}	\N	review.approved:22	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f08-7204-b0f2-d24206cc58f3	review	23	review.submitted	{"reviewId":23,"productId":5,"rating":5}	\N	review.submitted:23	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f10-7363-a9d9-ac4a74b233ed	review	23	review.approved	{"reviewId":23,"productId":5,"rating":5}	\N	review.approved:23	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f1f-71ea-b1ed-1fa378a247e1	review	24	review.submitted	{"reviewId":24,"productId":5,"rating":5}	\N	review.submitted:24	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f24-73a8-b2a2-751e8d4a7e30	review	24	review.approved	{"reviewId":24,"productId":5,"rating":5}	\N	review.approved:24	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f30-70bf-8ff3-9ad69d2e0c5e	review	25	review.submitted	{"reviewId":25,"productId":5,"rating":4}	\N	review.submitted:25	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f34-70c6-bb64-db10d65601e8	review	25	review.approved	{"reviewId":25,"productId":5,"rating":4}	\N	review.approved:25	PROCESSED	0	2026-09-04 06:01:26	2026-09-09 03:40:47	2026-09-04 06:01:26	2026-09-09 03:40:47
01a06a5d-2f95-7164-888b-fde6b4aefa17	review	28	review.approved	{"reviewId":28,"productId":5,"rating":4}	\N	review.approved:28	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2fcc-708f-b504-7e27e9a9b5f9	review	29	review.submitted	{"reviewId":29,"productId":6,"rating":4}	\N	review.submitted:29	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2fd6-71dd-872a-e05f21b7b55c	review	29	review.approved	{"reviewId":29,"productId":6,"rating":4}	\N	review.approved:29	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2fe8-731c-9439-80f9b6f6f155	review	30	review.submitted	{"reviewId":30,"productId":6,"rating":3}	\N	review.submitted:30	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2fee-73e9-a295-0b361bdb7cf2	review	30	review.approved	{"reviewId":30,"productId":6,"rating":3}	\N	review.approved:30	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2ff8-72b8-ba09-1f9c18048954	review	31	review.submitted	{"reviewId":31,"productId":6,"rating":5}	\N	review.submitted:31	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-2ffc-720f-b5fe-92f60f311c01	review	31	review.approved	{"reviewId":31,"productId":6,"rating":5}	\N	review.approved:31	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3016-736b-bffb-6a070ae6c986	review	32	review.submitted	{"reviewId":32,"productId":6,"rating":4}	\N	review.submitted:32	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-301f-71eb-8fe6-460a1aa9e5dc	review	32	review.approved	{"reviewId":32,"productId":6,"rating":4}	\N	review.approved:32	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-303e-7379-a1ae-4e86e3e85c59	review	33	review.submitted	{"reviewId":33,"productId":6,"rating":2}	\N	review.submitted:33	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-304e-7014-bf11-4153e29ae487	review	33	review.approved	{"reviewId":33,"productId":6,"rating":2}	\N	review.approved:33	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-306f-7318-8f0e-6abd258298b6	review	34	review.submitted	{"reviewId":34,"productId":6,"rating":4}	\N	review.submitted:34	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3077-7020-bb5d-e510841950cf	review	34	review.approved	{"reviewId":34,"productId":6,"rating":4}	\N	review.approved:34	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-30aa-72e6-b1a3-adef13dffdf2	review	35	review.submitted	{"reviewId":35,"productId":7,"rating":5}	\N	review.submitted:35	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-30bf-73a9-ae19-3aebfb0d4226	review	35	review.approved	{"reviewId":35,"productId":7,"rating":5}	\N	review.approved:35	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-30d7-703a-9b04-e5ff7c1e136a	review	36	review.submitted	{"reviewId":36,"productId":7,"rating":4}	\N	review.submitted:36	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-30dd-7022-81a8-2061293b609d	review	36	review.approved	{"reviewId":36,"productId":7,"rating":4}	\N	review.approved:36	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3103-7191-b351-80548eada5c0	review	37	review.submitted	{"reviewId":37,"productId":8,"rating":3}	\N	review.submitted:37	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-310e-716f-a5d2-f581f92c03fc	review	37	review.approved	{"reviewId":37,"productId":8,"rating":3}	\N	review.approved:37	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-312c-7164-90b3-1133e580d5e3	review	38	review.submitted	{"reviewId":38,"productId":8,"rating":4}	\N	review.submitted:38	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3133-72a6-bef2-a7df5863263e	review	38	review.approved	{"reviewId":38,"productId":8,"rating":4}	\N	review.approved:38	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-314a-70b0-84cd-4e4b06a62ff2	review	39	review.submitted	{"reviewId":39,"productId":8,"rating":2}	\N	review.submitted:39	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3151-739d-b18f-48010531c160	review	39	review.approved	{"reviewId":39,"productId":8,"rating":2}	\N	review.approved:39	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3173-718b-9266-e6a631f1a4fc	review	40	review.submitted	{"reviewId":40,"productId":8,"rating":5}	\N	review.submitted:40	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3181-736d-a447-0403d6077f4e	review	40	review.approved	{"reviewId":40,"productId":8,"rating":5}	\N	review.approved:40	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3195-7363-871f-3ed6c05e6aed	review	41	review.submitted	{"reviewId":41,"productId":8,"rating":3}	\N	review.submitted:41	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-319b-7148-83fc-0a8fe9fca4a3	review	41	review.approved	{"reviewId":41,"productId":8,"rating":3}	\N	review.approved:41	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31c2-73e0-8bef-7fcd951ddbc7	review	42	review.submitted	{"reviewId":42,"productId":9,"rating":5}	\N	review.submitted:42	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31c9-7088-9126-91d92fbd23b8	review	42	review.approved	{"reviewId":42,"productId":9,"rating":5}	\N	review.approved:42	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31e2-7329-8265-2e04401ecc19	review	43	review.submitted	{"reviewId":43,"productId":9,"rating":5}	\N	review.submitted:43	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31e8-71d4-8efb-7eaee55105cb	review	43	review.approved	{"reviewId":43,"productId":9,"rating":5}	\N	review.approved:43	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31f3-735b-bc69-44689babeb03	review	44	review.submitted	{"reviewId":44,"productId":9,"rating":4}	\N	review.submitted:44	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-31f7-727f-bbd3-6bb3ab941032	review	44	review.approved	{"reviewId":44,"productId":9,"rating":4}	\N	review.approved:44	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-320d-7204-9a99-7eea3c9d33e1	review	45	review.submitted	{"reviewId":45,"productId":9,"rating":5}	\N	review.submitted:45	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3211-72a1-99dd-0d04ad27c5b3	review	45	review.approved	{"reviewId":45,"productId":9,"rating":5}	\N	review.approved:45	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-321f-714c-ab98-07b864cfb1d0	review	46	review.submitted	{"reviewId":46,"productId":9,"rating":5}	\N	review.submitted:46	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3223-7377-a72e-742496d90ef1	review	46	review.approved	{"reviewId":46,"productId":9,"rating":5}	\N	review.approved:46	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-322d-7196-a3be-4e1735dfa08b	review	47	review.submitted	{"reviewId":47,"productId":9,"rating":5}	\N	review.submitted:47	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3230-7225-954a-7b38e61de12e	review	47	review.approved	{"reviewId":47,"productId":9,"rating":5}	\N	review.approved:47	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3250-73bf-a21e-87c027d1e8cf	review	48	review.submitted	{"reviewId":48,"productId":9,"rating":4}	\N	review.submitted:48	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3258-7150-850f-9aa5e3773e22	review	48	review.approved	{"reviewId":48,"productId":9,"rating":4}	\N	review.approved:48	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3268-7261-840e-06a6b767f999	review	49	review.submitted	{"reviewId":49,"productId":9,"rating":5}	\N	review.submitted:49	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-326d-716b-bfee-2b38b6cd5834	review	49	review.approved	{"reviewId":49,"productId":9,"rating":5}	\N	review.approved:49	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-327d-70f5-9a62-0b5b74e7330e	review	50	review.submitted	{"reviewId":50,"productId":9,"rating":5}	\N	review.submitted:50	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-3283-7162-b102-4c5cb808f24f	review	50	review.approved	{"reviewId":50,"productId":9,"rating":5}	\N	review.approved:50	PROCESSED	0	2026-09-04 06:01:27	2026-09-09 03:40:48	2026-09-04 06:01:27	2026-09-09 03:40:48
01a06a5d-351b-7095-b09d-c51b56f63ea6	review	76	review.approved	{"reviewId":76,"productId":16,"rating":4}	\N	review.approved:76	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-352a-7312-a31b-00deba0adfed	review	77	review.submitted	{"reviewId":77,"productId":16,"rating":5}	\N	review.submitted:77	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-352d-7246-be16-0582cd446373	review	77	review.approved	{"reviewId":77,"productId":16,"rating":5}	\N	review.approved:77	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-353a-72cd-b58c-22b5fb2119c6	review	78	review.submitted	{"reviewId":78,"productId":16,"rating":5}	\N	review.submitted:78	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-353e-7226-9988-c7c996e020d0	review	78	review.approved	{"reviewId":78,"productId":16,"rating":5}	\N	review.approved:78	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-354f-73e1-9c39-1b7056f8196a	review	79	review.submitted	{"reviewId":79,"productId":18,"rating":3}	\N	review.submitted:79	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3555-70ac-b177-e0bae6f5ba6d	review	79	review.approved	{"reviewId":79,"productId":18,"rating":3}	\N	review.approved:79	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3564-7250-9211-77c1527f5455	review	80	review.approved	{"reviewId":80,"productId":18,"rating":5}	\N	review.approved:80	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3570-72c3-ad0d-c5f19ba7b0ea	review	81	review.submitted	{"reviewId":81,"productId":18,"rating":4}	\N	review.submitted:81	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3574-7248-9122-5ecb57ee0115	review	81	review.approved	{"reviewId":81,"productId":18,"rating":4}	\N	review.approved:81	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3589-71de-ac93-94889db0cdf5	review	82	review.submitted	{"reviewId":82,"productId":18,"rating":4}	\N	review.submitted:82	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3591-705b-89d2-8c97f6873125	review	82	review.approved	{"reviewId":82,"productId":18,"rating":4}	\N	review.approved:82	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-359e-704d-9842-dc3cde79487d	review	83	review.submitted	{"reviewId":83,"productId":18,"rating":5}	\N	review.submitted:83	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36d9-725b-aed1-35f8c101dd1f	review	95	review.approved	{"reviewId":95,"productId":22,"rating":5}	\N	review.approved:95	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36ee-720a-be75-f2d93a9fd057	review	96	review.submitted	{"reviewId":96,"productId":22,"rating":5}	\N	review.submitted:96	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36f4-71a4-ad6c-b868d15b3a3c	review	96	review.approved	{"reviewId":96,"productId":22,"rating":5}	\N	review.approved:96	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-36ff-70bc-bca3-2affcff92c6d	review	97	review.submitted	{"reviewId":97,"productId":22,"rating":4}	\N	review.submitted:97	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3702-701e-8530-4dfb885cbc41	review	97	review.approved	{"reviewId":97,"productId":22,"rating":4}	\N	review.approved:97	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-370f-729f-a459-7f2a217ee82d	review	98	review.submitted	{"reviewId":98,"productId":22,"rating":5}	\N	review.submitted:98	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-3714-73e0-bf6d-a6f30e7c6edc	review	98	review.approved	{"reviewId":98,"productId":22,"rating":5}	\N	review.approved:98	PROCESSED	0	2026-09-04 06:01:28	2026-09-09 03:41:06	2026-09-04 06:01:28	2026-09-09 03:41:06
01a06a5d-37b4-733d-b9f2-1df32633708b	review	105	review.submitted	{"reviewId":105,"productId":24,"rating":5}	\N	review.submitted:105	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:06	2026-09-04 06:01:29	2026-09-09 03:41:06
01a06a5d-3730-730e-999f-2e04c169185f	review	99	review.submitted	{"reviewId":99,"productId":22,"rating":5}	\N	review.submitted:99	PROCESSED	0	2026-09-04 06:01:29	2026-09-09 03:41:06	2026-09-04 06:01:29	2026-09-09 03:41:06
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: payment_events; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.payment_events (id, payment_id, event_type, payload, created_at) FROM stdin;
1	1	charge.captured	{"simulated":true}	2026-09-08 23:30:42
2	2	charge.captured	{"simulated":true}	2026-09-08 23:31:11
3	3	charge.failed	{"charge_id":"chg_local_1","status":"DECLINED","code":null,"message":null,"method":null,"raw":{"charge_id":"chg_local_1","order_number":"TD-1126","status":"DECLINED"}}	2026-09-09 00:41:06
4	4	charge.captured	{"simulated":true}	2026-09-09 00:50:23
5	5	charge.captured	{"simulated":true}	2026-09-09 00:50:44
6	6	charge.captured	{"simulated":true}	2026-09-09 00:59:35
7	7	charge.captured	{"simulated":true}	2026-09-09 01:00:09
8	9	charge.captured	{"simulated":true}	2026-09-09 01:26:25
9	10	charge.captured	{"simulated":true}	2026-09-09 01:51:07
10	11	charge.captured	{"simulated":true}	2026-09-09 01:58:27
11	12	charge.captured	{"simulated":true}	2026-09-10 01:25:28
12	13	charge.captured	{"simulated":true}	2026-09-10 01:29:09
13	14	charge.captured	{"simulated":true}	2026-09-10 01:35:07
14	15	charge.captured	{"simulated":true}	2026-09-10 01:51:55
15	16	charge.captured	{"simulated":true}	2026-09-10 02:00:00
16	17	charge.captured	{"simulated":true}	2026-09-10 02:01:42
17	18	charge.captured	{"simulated":true}	2026-09-10 02:04:11
18	19	charge.captured	{"simulated":true}	2026-09-10 02:07:54
19	20	charge.captured	{"simulated":true}	2026-09-10 02:09:44
20	21	charge.captured	{"simulated":true}	2026-09-10 02:11:33
21	22	charge.captured	{"simulated":true}	2026-09-10 02:21:48
22	23	charge.captured	{"simulated":true}	2026-09-10 02:33:49
23	24	charge.captured	{"simulated":true}	2026-09-10 02:47:37
24	25	charge.captured	{"simulated":true}	2026-09-10 02:54:36
25	26	charge.captured	{"simulated":true}	2026-09-10 02:56:12
26	27	charge.captured	{"simulated":true}	2026-09-10 03:03:40
27	28	charge.captured	{"simulated":true}	2026-09-10 03:05:13
28	29	charge.captured	{"simulated":true}	2026-09-10 03:06:36
\.


--
-- Data for Name: payments; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.payments (id, order_id, provider, intent_id, method, masked_instrument, state, idempotency_key, created_at, updated_at) FROM stdin;
1	124	fake	fake_chg_124	\N	\N	CAPTURED	fake:fake_chg_124	2026-09-09 02:30:38	2026-09-09 02:30:42
2	125	fake	fake_chg_125	\N	\N	CAPTURED	fake:fake_chg_125	2026-09-09 02:31:10	2026-09-09 02:31:11
3	126	fake	fake_chg_126	\N	\N	FAILED	fake:intent:126	2026-09-09 03:41:05	2026-09-09 03:41:06
4	127	fake	fake_chg_127	\N	\N	CAPTURED	fake:fake_chg_127	2026-09-09 03:50:22	2026-09-09 03:50:22
5	128	fake	fake_chg_128	\N	\N	CAPTURED	fake:fake_chg_128	2026-09-09 03:50:42	2026-09-09 03:50:43
6	129	fake	fake_chg_129	\N	\N	CAPTURED	fake:fake_chg_129	2026-09-09 03:59:34	2026-09-09 03:59:34
7	130	fake	fake_chg_130	\N	\N	CAPTURED	fake:fake_chg_130	2026-09-09 04:00:07	2026-09-09 04:00:08
8	131	fake	fake_chg_131	\N	\N	CREATED	fake:intent:131	2026-09-09 04:04:28	2026-09-09 04:04:28
9	132	fake	fake_chg_132	\N	\N	CAPTURED	fake:fake_chg_132	2026-09-09 04:26:23	2026-09-09 04:26:24
10	133	fake	fake_chg_133	\N	\N	CAPTURED	fake:fake_chg_133	2026-09-09 04:51:06	2026-09-09 04:51:07
11	134	fake	fake_chg_134	\N	\N	CAPTURED	fake:fake_chg_134	2026-09-09 04:58:25	2026-09-09 04:58:27
12	135	fake	fake_chg_135	\N	\N	CAPTURED	fake:fake_chg_135	2026-09-10 04:25:27	2026-09-10 04:25:28
13	136	fake	fake_chg_136	\N	\N	CAPTURED	fake:fake_chg_136	2026-09-10 04:29:08	2026-09-10 04:29:09
14	137	fake	fake_chg_137	\N	\N	CAPTURED	fake:fake_chg_137	2026-09-10 04:35:05	2026-09-10 04:35:07
15	138	fake	fake_chg_138	\N	\N	CAPTURED	fake:fake_chg_138	2026-09-10 04:51:53	2026-09-10 04:51:54
16	139	fake	fake_chg_139	\N	\N	CAPTURED	fake:fake_chg_139	2026-09-10 04:59:58	2026-09-10 04:59:59
17	140	fake	fake_chg_140	\N	\N	CAPTURED	fake:fake_chg_140	2026-09-10 05:01:38	2026-09-10 05:01:42
18	141	fake	fake_chg_141	\N	\N	CAPTURED	fake:fake_chg_141	2026-09-10 05:04:09	2026-09-10 05:04:11
19	142	fake	fake_chg_142	\N	\N	CAPTURED	fake:fake_chg_142	2026-09-10 05:07:52	2026-09-10 05:07:54
20	143	fake	fake_chg_143	\N	\N	CAPTURED	fake:fake_chg_143	2026-09-10 05:09:42	2026-09-10 05:09:43
21	144	fake	fake_chg_144	\N	\N	CAPTURED	fake:fake_chg_144	2026-09-10 05:11:31	2026-09-10 05:11:32
22	145	fake	fake_chg_145	\N	\N	CAPTURED	fake:fake_chg_145	2026-09-10 05:21:46	2026-09-10 05:21:47
23	146	fake	fake_chg_146	\N	\N	CAPTURED	fake:fake_chg_146	2026-09-10 05:33:47	2026-09-10 05:33:48
24	147	fake	fake_chg_147	\N	\N	CAPTURED	fake:fake_chg_147	2026-09-10 05:47:37	2026-09-10 05:47:37
25	148	fake	fake_chg_148	\N	\N	CAPTURED	fake:fake_chg_148	2026-09-10 05:54:34	2026-09-10 05:54:36
26	149	fake	fake_chg_149	\N	\N	CAPTURED	fake:fake_chg_149	2026-09-10 05:56:10	2026-09-10 05:56:11
27	150	fake	fake_chg_150	\N	\N	CAPTURED	fake:fake_chg_150	2026-09-10 06:03:38	2026-09-10 06:03:39
28	151	fake	fake_chg_151	\N	\N	CAPTURED	fake:fake_chg_151	2026-09-10 06:05:12	2026-09-10 06:05:13
29	152	fake	fake_chg_152	\N	\N	CAPTURED	fake:fake_chg_152	2026-09-10 06:06:35	2026-09-10 06:06:36
30	153	fake	fake_chg_153	\N	\N	CREATED	fake:intent:153	2026-09-11 00:39:33	2026-09-11 00:39:33
\.


--
-- Data for Name: product_authors; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_authors (id, product_id, name, slug, bio, profile_url, consented, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_fact_evidence; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_fact_evidence (id, fact_id, asset_id, asset_sha256, source_provider, locator, extractor, snippet, confidence, contradicts, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_facts; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_facts (id, product_id, key, int_value, text_value, created_at, updated_at) FROM stdin;
1	3	pages_count	86	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
2	3	lessons_count	24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
3	4	slides_count	62	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
4	5	worksheets_count	40	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
5	5	activities_count	18	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
6	6	pages_count	32	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
7	7	pages_count	12	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
8	8	pages_count	104	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
9	8	lessons_count	30	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
10	9	slides_count	118	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
11	10	worksheets_count	28	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
12	10	activities_count	15	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
13	11	pages_count	24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
14	12	pages_count	96	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
15	13	pages_count	120	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
16	13	lessons_count	34	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
17	14	pages_count	44	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
18	15	slides_count	74	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
19	16	worksheets_count	36	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
20	16	activities_count	20	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
21	17	pages_count	92	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
22	17	lessons_count	26	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
23	18	pages_count	38	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
24	19	pages_count	20	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
25	20	pages_count	14	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
26	21	pages_count	108	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
27	22	slides_count	68	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
28	23	worksheets_count	30	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
29	23	activities_count	12	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
30	24	pages_count	88	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
31	24	lessons_count	22	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
32	25	pages_count	30	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
33	26	pages_count	18	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
\.


--
-- Data for Name: product_faqs; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_faqs (id, product_id, question, answer, "position", created_at, updated_at) FROM stdin;
1	3	ماذا يتضمّن ملف تحضير لمادة الرياضيات؟	يتضمّن الملف 86 صفحة و24 درسًا، وهو مُعدّ لمادة الرياضيات — الصف السادس الابتدائي، الفصل الدراسي الأول بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
2	3	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
3	3	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
4	3	هل الملف مناسب لـالصف السادس الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف السادس الابتدائي في الفصل الدراسي الأول ضمن مادة الرياضيات وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
5	4	ماذا يتضمّن ملف عرض بوربوينت لمادة العلوم؟	يتضمّن الملف 62 شريحة، وهو مُعدّ لمادة العلوم — الصف الرابع الابتدائي، الفصل الدراسي الثاني بصيغة PPTX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
6	4	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة PPTX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
7	4	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
8	4	هل الملف مناسب لـالصف الرابع الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الرابع الابتدائي في الفصل الدراسي الثاني ضمن مادة العلوم وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
9	5	ماذا يتضمّن ملف أوراق عمل لمادة لغتي؟	يتضمّن الملف 40 ورقة عمل و18 نشاطًا، وهو مُعدّ لمادة لغتي — الصف الثالث الابتدائي، الفصل الدراسي الأول بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
10	5	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
11	5	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
12	5	هل الملف مناسب لـالصف الثالث الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الثالث الابتدائي في الفصل الدراسي الأول ضمن مادة لغتي وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
13	6	ماذا يتضمّن ملف اختبارات لمادة الدراسات الإسلامية؟	يتضمّن الملف 32 صفحة، وهو مُعدّ لمادة الدراسات الإسلامية — الصف الخامس الابتدائي، الفصل الدراسي الثالث بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
14	6	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
15	6	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
16	6	هل الملف مناسب لـالصف الخامس الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الخامس الابتدائي في الفصل الدراسي الثالث ضمن مادة الدراسات الإسلامية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
17	7	ماذا يتضمّن ملف توزيع لمادة المهارات الرقمية؟	يتضمّن الملف 12 صفحة، وهو مُعدّ لمادة المهارات الرقمية — الصف الأول المتوسط، الفصل الدراسي الأول بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
18	7	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
19	7	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
20	7	هل الملف مناسب لـالصف الأول المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الأول المتوسط في الفصل الدراسي الأول ضمن مادة المهارات الرقمية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
21	8	ماذا يتضمّن ملف تحضير لمادة اللغة الإنجليزية؟	يتضمّن الملف 104 صفحة و30 درسًا، وهو مُعدّ لمادة اللغة الإنجليزية — الصف الثاني المتوسط، الفصل الدراسي الثاني بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
22	8	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
23	8	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
24	8	هل الملف مناسب لـالصف الثاني المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الثاني المتوسط في الفصل الدراسي الثاني ضمن مادة اللغة الإنجليزية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
50	15	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة PPTX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
25	9	ماذا يتضمّن ملف عرض بوربوينت لمادة الفيزياء؟	يتضمّن الملف 118 شريحة، وهو مُعدّ لمادة الفيزياء — الصف الأول الثانوي، الفصل الدراسي الأول بصيغة PPTX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
26	9	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة PPTX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
27	9	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
28	9	هل الملف مناسب لـالصف الأول الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الأول الثانوي في الفصل الدراسي الأول ضمن مادة الفيزياء وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
29	10	ماذا يتضمّن ملف أوراق عمل لمادة الكيمياء؟	يتضمّن الملف 28 ورقة عمل و15 نشاطًا، وهو مُعدّ لمادة الكيمياء — الصف الثاني الثانوي، الفصل الدراسي الثاني بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
30	10	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
31	10	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
32	10	هل الملف مناسب لـالصف الثاني الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثاني الثانوي في الفصل الدراسي الثاني ضمن مادة الكيمياء وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
33	11	ماذا يتضمّن ملف وسائل تعليمية لمادة القرآن الكريم؟	يتضمّن الملف 24 صفحة، وهو مُعدّ لمادة القرآن الكريم — الصف الثاني الابتدائي، الفصل الدراسي الأول بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
34	11	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
35	11	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
36	11	هل الملف مناسب لـالصف الثاني الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الثاني الابتدائي في الفصل الدراسي الأول ضمن مادة القرآن الكريم وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
37	12	ماذا يتضمّن ملف حلول لمادة الرياضيات؟	يتضمّن الملف 96 صفحة، وهو مُعدّ لمادة الرياضيات — الصف الثالث المتوسط، الفصل الدراسي الثالث بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
38	12	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
39	12	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
40	12	هل الملف مناسب لـالصف الثالث المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الثالث المتوسط في الفصل الدراسي الثالث ضمن مادة الرياضيات وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
41	13	ماذا يتضمّن ملف تحضير لمادة الدراسات الاجتماعية؟	يتضمّن الملف 120 صفحة و34 درسًا، وهو مُعدّ لمادة الدراسات الاجتماعية — الصف الثالث الثانوي، الفصل الدراسي الثاني بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
42	13	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
43	13	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
44	13	هل الملف مناسب لـالصف الثالث الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثالث الثانوي في الفصل الدراسي الثاني ضمن مادة الدراسات الاجتماعية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
45	14	ماذا يتضمّن ملف اختبارات لمادة الأحياء؟	يتضمّن الملف 44 صفحة، وهو مُعدّ لمادة الأحياء — الصف الثالث الثانوي، الفصل الدراسي الأول بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
46	14	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
47	14	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
48	14	هل الملف مناسب لـالصف الثالث الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثالث الثانوي في الفصل الدراسي الأول ضمن مادة الأحياء وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
49	15	ماذا يتضمّن ملف عرض بوربوينت لمادة اللغة العربية؟	يتضمّن الملف 74 شريحة، وهو مُعدّ لمادة اللغة العربية — الصف الأول المتوسط، الفصل الدراسي الثاني بصيغة PPTX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
51	15	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
52	15	هل الملف مناسب لـالصف الأول المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الأول المتوسط في الفصل الدراسي الثاني ضمن مادة اللغة العربية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
53	16	ماذا يتضمّن ملف أوراق عمل لمادة الرياضيات؟	يتضمّن الملف 36 ورقة عمل و20 نشاطًا، وهو مُعدّ لمادة الرياضيات — الصف الأول الابتدائي، الفصل الدراسي الأول بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
54	16	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
55	16	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
56	16	هل الملف مناسب لـالصف الأول الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الأول الابتدائي في الفصل الدراسي الأول ضمن مادة الرياضيات وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
57	17	ماذا يتضمّن ملف تحضير لمادة العلوم؟	يتضمّن الملف 92 صفحة و26 درسًا، وهو مُعدّ لمادة العلوم — الصف الخامس الابتدائي، الفصل الدراسي الثالث بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
58	17	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
59	17	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
60	17	هل الملف مناسب لـالصف الخامس الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الخامس الابتدائي في الفصل الدراسي الثالث ضمن مادة العلوم وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
61	18	ماذا يتضمّن ملف اختبارات لمادة اللغة الإنجليزية؟	يتضمّن الملف 38 صفحة، وهو مُعدّ لمادة اللغة الإنجليزية — الصف الثالث المتوسط، الفصل الدراسي الأول بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
62	18	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
63	18	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
64	18	هل الملف مناسب لـالصف الثالث المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الثالث المتوسط في الفصل الدراسي الأول ضمن مادة اللغة الإنجليزية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
65	19	ماذا يتضمّن ملف وسائل تعليمية لمادة التربية الفنية؟	يتضمّن الملف 20 صفحة، وهو مُعدّ لمادة التربية الفنية — الصف الثالث الابتدائي، الفصل الدراسي الثاني بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
66	19	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
67	19	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
68	19	هل الملف مناسب لـالصف الثالث الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الثالث الابتدائي في الفصل الدراسي الثاني ضمن مادة التربية الفنية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
69	20	ماذا يتضمّن ملف توزيع لمادة الكفايات اللغوية؟	يتضمّن الملف 14 صفحة، وهو مُعدّ لمادة الكفايات اللغوية — الصف الثاني الثانوي، الفصل الدراسي الأول بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
70	20	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
71	20	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
72	20	هل الملف مناسب لـالصف الثاني الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثاني الثانوي في الفصل الدراسي الأول ضمن مادة الكفايات اللغوية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
73	21	ماذا يتضمّن ملف حلول لمادة الفيزياء؟	يتضمّن الملف 108 صفحة، وهو مُعدّ لمادة الفيزياء — الصف الثالث الثانوي، الفصل الدراسي الثالث بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
74	21	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
75	21	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
76	21	هل الملف مناسب لـالصف الثالث الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثالث الثانوي في الفصل الدراسي الثالث ضمن مادة الفيزياء وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
77	22	ماذا يتضمّن ملف عرض بوربوينت لمادة الدراسات الإسلامية؟	يتضمّن الملف 68 شريحة، وهو مُعدّ لمادة الدراسات الإسلامية — الصف السادس الابتدائي، الفصل الدراسي الأول بصيغة PPTX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
78	22	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة PPTX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
79	22	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
80	22	هل الملف مناسب لـالصف السادس الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف السادس الابتدائي في الفصل الدراسي الأول ضمن مادة الدراسات الإسلامية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
81	23	ماذا يتضمّن ملف أوراق عمل لمادة المهارات الحياتية؟	يتضمّن الملف 30 ورقة عمل و12 نشاطًا، وهو مُعدّ لمادة المهارات الحياتية — الصف الثاني المتوسط، الفصل الدراسي الثالث بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
82	23	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
83	23	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
84	23	هل الملف مناسب لـالصف الثاني المتوسط؟	نعم، هذا الملف مُصنَّف لـالصف الثاني المتوسط في الفصل الدراسي الثالث ضمن مادة المهارات الحياتية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
85	24	ماذا يتضمّن ملف تحضير لمادة التفكير الناقد؟	يتضمّن الملف 88 صفحة و22 درسًا، وهو مُعدّ لمادة التفكير الناقد — الصف الأول الثانوي، الفصل الدراسي الثاني بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
86	24	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
87	24	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
88	24	هل الملف مناسب لـالصف الأول الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الأول الثانوي في الفصل الدراسي الثاني ضمن مادة التفكير الناقد وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
89	25	ماذا يتضمّن ملف اختبارات لمادة علم البيئة؟	يتضمّن الملف 30 صفحة، وهو مُعدّ لمادة علم البيئة — الصف الثاني الثانوي، الفصل الدراسي الثالث بصيغة DOCX.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
90	25	هل يمكنني تعديل الملف؟	نعم، الملف بصيغة DOCX قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
91	25	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:25	2026-09-04 06:01:25
92	25	هل الملف مناسب لـالصف الثاني الثانوي؟	نعم، هذا الملف مُصنَّف لـالصف الثاني الثانوي في الفصل الدراسي الثالث ضمن مادة علم البيئة وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:25	2026-09-04 06:01:25
93	26	ماذا يتضمّن ملف وسائل تعليمية لمادة التربية البدنية؟	يتضمّن الملف 18 صفحة، وهو مُعدّ لمادة التربية البدنية — الصف الأول الابتدائي، الفصل الدراسي الثاني بصيغة PDF.	0	2026-09-04 06:01:25	2026-09-04 06:01:25
94	26	هل يمكنني تعديل الملف؟	الملف بصيغة PDF وجاهز للطباعة كما هو، وغير قابل للتعديل.	1	2026-09-04 06:01:25	2026-09-04 06:01:25
95	26	متى يصلني الملف بعد الدفع؟	يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.	2	2026-09-04 06:01:26	2026-09-04 06:01:26
96	26	هل الملف مناسب لـالصف الأول الابتدائي؟	نعم، هذا الملف مُصنَّف لـالصف الأول الابتدائي في الفصل الدراسي الثاني ضمن مادة التربية البدنية وفق الخطة المعتمدة للعام الدراسي الحالي.	3	2026-09-04 06:01:26	2026-09-04 06:01:26
\.


--
-- Data for Name: product_files; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_files (id, product_id, asset_id, role, "position", created_at, updated_at) FROM stdin;
1	1	1	main	1	2026-09-04 06:01:24	2026-09-04 06:01:24
2	2	2	main	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	3	3	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
4	4	4	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
5	5	5	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
6	6	6	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
7	7	7	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
8	8	8	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
9	9	9	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
10	10	10	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
11	11	11	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
12	12	12	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
13	13	13	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
14	14	14	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
15	15	15	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
16	16	16	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
17	17	17	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
18	18	18	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
19	19	19	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
20	20	20	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
21	21	21	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
22	22	22	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
23	23	23	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
24	24	24	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
25	25	25	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
26	26	26	sample	1	2026-09-04 06:01:25	2026-09-04 06:01:25
\.


--
-- Data for Name: product_knowledge_facts; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_knowledge_facts (id, product_id, key, value_text, value_int, confidence, verification_state, extraction_method, model, pipeline_version, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_layouts; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_layouts (id, product_id, draft_layout, published_layout, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: product_media; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_media (id, product_id, url, alt_ar, width, height, "position", is_cover, created_at, updated_at) FROM stdin;
1	3	/storage/design-preview/DESIGN-PREVIEW-01.svg	غلاف معاينة مولّد — تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
2	4	/storage/design-preview/DESIGN-PREVIEW-02.svg	غلاف معاينة مولّد — عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
3	5	/storage/design-preview/DESIGN-PREVIEW-03.svg	غلاف معاينة مولّد — أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
4	6	/storage/design-preview/DESIGN-PREVIEW-04.svg	غلاف معاينة مولّد — اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
5	7	/storage/design-preview/DESIGN-PREVIEW-05.svg	غلاف معاينة مولّد — توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
6	8	/storage/design-preview/DESIGN-PREVIEW-06.svg	غلاف معاينة مولّد — تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
7	9	/storage/design-preview/DESIGN-PREVIEW-07.svg	غلاف معاينة مولّد — عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
8	10	/storage/design-preview/DESIGN-PREVIEW-08.svg	غلاف معاينة مولّد — أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
9	11	/storage/design-preview/DESIGN-PREVIEW-09.svg	غلاف معاينة مولّد — وسائل تعليمية القرآن الكريم — الصف الثاني الابتدائي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
10	12	/storage/design-preview/DESIGN-PREVIEW-10.svg	غلاف معاينة مولّد — حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
11	13	/storage/design-preview/DESIGN-PREVIEW-11.svg	غلاف معاينة مولّد — تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
12	14	/storage/design-preview/DESIGN-PREVIEW-12.svg	غلاف معاينة مولّد — اختبارات الأحياء — الصف الثالث الثانوي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
13	15	/storage/design-preview/DESIGN-PREVIEW-13.svg	غلاف معاينة مولّد — عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
14	16	/storage/design-preview/DESIGN-PREVIEW-14.svg	غلاف معاينة مولّد — أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
15	17	/storage/design-preview/DESIGN-PREVIEW-15.svg	غلاف معاينة مولّد — تحضير العلوم — الصف الخامس الابتدائي — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
16	18	/storage/design-preview/DESIGN-PREVIEW-16.svg	غلاف معاينة مولّد — اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
17	19	/storage/design-preview/DESIGN-PREVIEW-17.svg	غلاف معاينة مولّد — وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
18	20	/storage/design-preview/DESIGN-PREVIEW-18.svg	غلاف معاينة مولّد — توزيع الكفايات اللغوية — الصف الثاني الثانوي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
19	21	/storage/design-preview/DESIGN-PREVIEW-19.svg	غلاف معاينة مولّد — حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
20	22	/storage/design-preview/DESIGN-PREVIEW-20.svg	غلاف معاينة مولّد — عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
21	23	/storage/design-preview/DESIGN-PREVIEW-21.svg	غلاف معاينة مولّد — أوراق عمل المهارات الحياتية — الصف الثاني المتوسط — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
22	24	/storage/design-preview/DESIGN-PREVIEW-22.svg	غلاف معاينة مولّد — تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
23	25	/storage/design-preview/DESIGN-PREVIEW-23.svg	غلاف معاينة مولّد — اختبارات علم البيئة — الصف الثاني الثانوي — الفصل الدراسي الثالث	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
24	26	/storage/design-preview/DESIGN-PREVIEW-24.svg	غلاف معاينة مولّد — وسائل تعليمية التربية البدنية — الصف الأول الابتدائي — الفصل الدراسي الثاني	1200	900	0	t	2026-09-04 06:01:25	2026-09-04 06:01:25
\.


--
-- Data for Name: product_prices; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_prices (id, product_id, regular_minor, sale_minor, sale_from, sale_to, currency, created_at, updated_at) FROM stdin;
1	1	40000	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
2	2	30000	25000	2026-09-03 06:01:24	2026-10-04 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
3	3	3500	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
4	4	4500	2900	2026-09-01 06:01:24	2026-09-15 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
5	5	2500	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
6	6	3000	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
7	7	1500	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
8	8	3900	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
9	9	5500	4400	2026-09-03 06:01:24	2026-09-24 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
10	10	3200	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
11	11	2200	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
12	12	2800	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
13	13	4200	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
14	14	3600	2700	2026-09-11 06:01:24	2026-10-02 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
15	15	4800	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
16	16	2600	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
17	17	3700	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
18	18	3100	2450	2026-08-30 06:01:24	2026-09-13 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
19	19	1900	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
20	20	1700	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
21	21	3300	\N	\N	\N	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
22	22	4100	3280	2026-09-02 06:01:24	2026-09-18 06:01:24	SAR	2026-09-04 06:01:24	2026-09-04 06:01:24
23	23	2400	1900	2026-08-05 06:01:24	2026-09-01 06:01:24	SAR	2026-09-04 06:01:25	2026-09-04 06:01:25
24	24	4600	\N	\N	\N	SAR	2026-09-04 06:01:25	2026-09-04 06:01:25
25	25	2900	\N	\N	\N	SAR	2026-09-04 06:01:25	2026-09-04 06:01:25
26	26	1600	\N	\N	\N	SAR	2026-09-04 06:01:25	2026-09-04 07:10:30
\.


--
-- Data for Name: product_seo; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_seo (id, product_id, seo_title, seo_desc, quick_answer, synonyms, canonical_override, created_at, updated_at) FROM stdin;
1	3	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول — تحميل فوري	دفتر تحضير جاهز لمادة الرياضيات — الصف السادس الابتدائي، الفصل الدراسي الأول. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	نعم، تحضير لمادة الرياضيات (الصف السادس الابتدائي، الفصل الدراسي الأول) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
2	4	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني — تحميل فوري	عرض تقديمي متكامل لمادة العلوم — الصف الرابع الابتدائي، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	نعم، عرض بوربوينت لمادة العلوم (الصف الرابع الابتدائي، الفصل الدراسي الثاني) متاح بصيغة PPTX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
3	5	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول — تحميل فوري	حزمة أوراق عمل وأنشطة صفّية لمادة لغتي — الصف الثالث الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع.	نعم، أوراق عمل لمادة لغتي (الصف الثالث الابتدائي، الفصل الدراسي الأول) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
4	6	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث — تحميل فوري	بنك أسئلة ونماذج اختبار لمادة الدراسات الإسلامية — الصف الخامس الابتدائي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة.	نعم، اختبارات لمادة الدراسات الإسلامية (الصف الخامس الابتدائي، الفصل الدراسي الثالث) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
5	7	توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول — تحميل فوري	توزيع المنهج الأسبوعي لمادة المهارات الرقمية — الصف الأول المتوسط، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي.	نعم، توزيع لمادة المهارات الرقمية (الصف الأول المتوسط، الفصل الدراسي الأول) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
6	8	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني — تحميل فوري	دفتر تحضير جاهز لمادة اللغة الإنجليزية — الصف الثاني المتوسط، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	نعم، تحضير لمادة اللغة الإنجليزية (الصف الثاني المتوسط، الفصل الدراسي الثاني) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
7	9	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول — تحميل فوري	عرض تقديمي متكامل لمادة الفيزياء — الصف الأول الثانوي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	نعم، عرض بوربوينت لمادة الفيزياء (الصف الأول الثانوي، الفصل الدراسي الأول) متاح بصيغة PPTX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
8	10	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني — تحميل فوري	حزمة أوراق عمل وأنشطة صفّية لمادة الكيمياء — الصف الثاني الثانوي، الفصل الدراسي الثاني، جاهزة للطباعة والتوزيع.	نعم، أوراق عمل لمادة الكيمياء (الصف الثاني الثانوي، الفصل الدراسي الثاني) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
9	11	وسائل تعليمية القرآن الكريم — الصف الثاني الابتدائي — الفصل الدراسي الأول — تحميل فوري	وسائل ولوحات تعليمية لمادة القرآن الكريم — الصف الثاني الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتعليق داخل الفصل.	نعم، وسائل تعليمية لمادة القرآن الكريم (الصف الثاني الابتدائي، الفصل الدراسي الأول) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
10	12	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث — تحميل فوري	حلول تمارين وأسئلة كتاب الرياضيات — الصف الثالث المتوسط، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة.	نعم، حلول لمادة الرياضيات (الصف الثالث المتوسط، الفصل الدراسي الثالث) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
11	13	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني — تحميل فوري	دفتر تحضير جاهز لمادة الدراسات الاجتماعية — الصف الثالث الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	نعم، تحضير لمادة الدراسات الاجتماعية (الصف الثالث الثانوي، الفصل الدراسي الثاني) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
12	14	اختبارات الأحياء — الصف الثالث الثانوي — الفصل الدراسي الأول — تحميل فوري	بنك أسئلة ونماذج اختبار لمادة الأحياء — الصف الثالث الثانوي، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة.	نعم، اختبارات لمادة الأحياء (الصف الثالث الثانوي، الفصل الدراسي الأول) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
13	15	عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني — تحميل فوري	عرض تقديمي متكامل لمادة اللغة العربية — الصف الأول المتوسط، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	نعم، عرض بوربوينت لمادة اللغة العربية (الصف الأول المتوسط، الفصل الدراسي الثاني) متاح بصيغة PPTX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
14	16	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول — تحميل فوري	حزمة أوراق عمل وأنشطة صفّية لمادة الرياضيات — الصف الأول الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع.	نعم، أوراق عمل لمادة الرياضيات (الصف الأول الابتدائي، الفصل الدراسي الأول) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
15	17	تحضير العلوم — الصف الخامس الابتدائي — الفصل الدراسي الثالث — تحميل فوري	دفتر تحضير جاهز لمادة العلوم — الصف الخامس الابتدائي، الفصل الدراسي الثالث. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	نعم، تحضير لمادة العلوم (الصف الخامس الابتدائي، الفصل الدراسي الثالث) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
16	18	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول — تحميل فوري	بنك أسئلة ونماذج اختبار لمادة اللغة الإنجليزية — الصف الثالث المتوسط، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة.	نعم، اختبارات لمادة اللغة الإنجليزية (الصف الثالث المتوسط، الفصل الدراسي الأول) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
17	19	وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني — تحميل فوري	وسائل ولوحات تعليمية لمادة التربية الفنية — الصف الثالث الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل.	نعم، وسائل تعليمية لمادة التربية الفنية (الصف الثالث الابتدائي، الفصل الدراسي الثاني) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
18	20	توزيع الكفايات اللغوية — الصف الثاني الثانوي — الفصل الدراسي الأول — تحميل فوري	توزيع المنهج الأسبوعي لمادة الكفايات اللغوية — الصف الثاني الثانوي، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي.	نعم، توزيع لمادة الكفايات اللغوية (الصف الثاني الثانوي، الفصل الدراسي الأول) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
19	21	حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث — تحميل فوري	حلول تمارين وأسئلة كتاب الفيزياء — الصف الثالث الثانوي، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة.	نعم، حلول لمادة الفيزياء (الصف الثالث الثانوي، الفصل الدراسي الثالث) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
20	22	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول — تحميل فوري	عرض تقديمي متكامل لمادة الدراسات الإسلامية — الصف السادس الابتدائي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	نعم، عرض بوربوينت لمادة الدراسات الإسلامية (الصف السادس الابتدائي، الفصل الدراسي الأول) متاح بصيغة PPTX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:24	2026-09-04 06:01:24
21	23	أوراق عمل المهارات الحياتية — الصف الثاني المتوسط — الفصل الدراسي الثالث — تحميل فوري	حزمة أوراق عمل وأنشطة صفّية لمادة المهارات الحياتية — الصف الثاني المتوسط، الفصل الدراسي الثالث، جاهزة للطباعة والتوزيع.	نعم، أوراق عمل لمادة المهارات الحياتية (الصف الثاني المتوسط، الفصل الدراسي الثالث) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
22	24	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني — تحميل فوري	دفتر تحضير جاهز لمادة التفكير الناقد — الصف الأول الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	نعم، تحضير لمادة التفكير الناقد (الصف الأول الثانوي، الفصل الدراسي الثاني) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
23	25	اختبارات علم البيئة — الصف الثاني الثانوي — الفصل الدراسي الثالث — تحميل فوري	بنك أسئلة ونماذج اختبار لمادة علم البيئة — الصف الثاني الثانوي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة.	نعم، اختبارات لمادة علم البيئة (الصف الثاني الثانوي، الفصل الدراسي الثالث) متاح بصيغة DOCX. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
24	26	وسائل تعليمية التربية البدنية — الصف الأول الابتدائي — الفصل الدراسي الثاني — تحميل فوري	وسائل ولوحات تعليمية لمادة التربية البدنية — الصف الأول الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل.	نعم، وسائل تعليمية لمادة التربية البدنية (الصف الأول الابتدائي، الفصل الدراسي الثاني) متاح بصيغة PDF. يصلك رابط التحميل مباشرة بعد إتمام الدفع.	\N	\N	2026-09-04 06:01:25	2026-09-04 06:01:25
\.


--
-- Data for Name: product_translations; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.product_translations (id, product_id, locale_code, title, subtitle, short_desc, description, slug, status, is_indexable, published_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.products (id, sku, slug, title, subtitle, short_desc, description, resource_type, format, stage_id, grade_id, subject_id, semester_id, editable, status, replacement_product_id, fulfillment_type, published_at, content_hash, created_at, updated_at, featured) FROM stdin;
1	DEV-COMMERCE-001	dev-commerce-001	[تجريبي للتطوير] منتج رقمي بسعر ثابت	\N	\N	\N	\N	\N	\N	\N	\N	\N	unset	published	\N	digital	2026-09-04 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
2	DEV-COMMERCE-002	dev-commerce-002	[تجريبي للتطوير] منتج رقمي عليه تخفيض	\N	\N	\N	\N	\N	\N	\N	\N	\N	unset	published	\N	digital	2026-09-04 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
3	DESIGN-PREVIEW-01	تحضير-الرياضيات-سادس-ابتدائي-الفصل-الأول	تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول	الصف السادس الابتدائي · الفصل الدراسي الأول	دفتر تحضير جاهز لمادة الرياضيات — الصف السادس الابتدائي، الفصل الدراسي الأول. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	دفتر تحضير جاهز لمادة الرياضيات — الصف السادس الابتدائي، الفصل الدراسي الأول. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	تحضير	DOCX	1	6	4	1	yes	published	\N	digital	2026-05-31 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
5	DESIGN-PREVIEW-03	أوراق-عمل-لغتي-ثالث-ابتدائي-الفصل-الأول	أوراق عمل لغتي — الصف الثالث الابتدائي — الفصل الدراسي الأول	الصف الثالث الابتدائي · الفصل الدراسي الأول	حزمة أوراق عمل وأنشطة صفّية لمادة لغتي — الصف الثالث الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع.	حزمة أوراق عمل وأنشطة صفّية لمادة لغتي — الصف الثالث الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	أوراق عمل	PDF	1	3	1	1	no	published	\N	digital	2026-06-28 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
7	DESIGN-PREVIEW-05	توزيع-المهارات-الرقمية-أول-متوسط-الفصل-الأول	توزيع المهارات الرقمية — الصف الأول المتوسط — الفصل الدراسي الأول	الصف الأول المتوسط · الفصل الدراسي الأول	توزيع المنهج الأسبوعي لمادة المهارات الرقمية — الصف الأول المتوسط، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي.	توزيع المنهج الأسبوعي لمادة المهارات الرقمية — الصف الأول المتوسط، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	توزيع	PDF	2	7	13	1	no	published	\N	digital	2026-07-11 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
8	DESIGN-PREVIEW-06	تحضير-اللغة-الإنجليزية-ثاني-متوسط-الفصل-الثاني	تحضير اللغة الإنجليزية — الصف الثاني المتوسط — الفصل الدراسي الثاني	الصف الثاني المتوسط · الفصل الدراسي الثاني	دفتر تحضير جاهز لمادة اللغة الإنجليزية — الصف الثاني المتوسط، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	دفتر تحضير جاهز لمادة اللغة الإنجليزية — الصف الثاني المتوسط، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	تحضير	DOCX	2	8	12	2	yes	published	\N	digital	2026-07-19 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
10	DESIGN-PREVIEW-08	أوراق-عمل-الكيمياء-ثاني-ثانوي-الفصل-الثاني	أوراق عمل الكيمياء — الصف الثاني الثانوي — الفصل الدراسي الثاني	الصف الثاني الثانوي · الفصل الدراسي الثاني	حزمة أوراق عمل وأنشطة صفّية لمادة الكيمياء — الصف الثاني الثانوي، الفصل الدراسي الثاني، جاهزة للطباعة والتوزيع.	حزمة أوراق عمل وأنشطة صفّية لمادة الكيمياء — الصف الثاني الثانوي، الفصل الدراسي الثاني، جاهزة للطباعة والتوزيع. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	أوراق عمل	PDF	3	11	7	2	no	published	\N	digital	2026-08-02 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
11	DESIGN-PREVIEW-09	وسائل-تعليمية-القرآن-الكريم-ثاني-ابتدائي-الفصل-الأول	وسائل تعليمية القرآن الكريم — الصف الثاني الابتدائي — الفصل الدراسي الأول	الصف الثاني الابتدائي · الفصل الدراسي الأول	وسائل ولوحات تعليمية لمادة القرآن الكريم — الصف الثاني الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتعليق داخل الفصل.	وسائل ولوحات تعليمية لمادة القرآن الكريم — الصف الثاني الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتعليق داخل الفصل. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	وسائل تعليمية	PDF	1	2	10	1	no	published	\N	digital	2026-08-09 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
12	DESIGN-PREVIEW-10	حلول-الرياضيات-ثالث-متوسط-الفصل-الثالث	حلول الرياضيات — الصف الثالث المتوسط — الفصل الدراسي الثالث	الصف الثالث المتوسط · الفصل الدراسي الثالث	حلول تمارين وأسئلة كتاب الرياضيات — الصف الثالث المتوسط، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة.	حلول تمارين وأسئلة كتاب الرياضيات — الصف الثالث المتوسط، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	حلول	PDF	2	9	4	3	no	published	\N	digital	2026-08-17 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
14	DESIGN-PREVIEW-12	اختبارات-الأحياء-ثالث-ثانوي-الفصل-الأول	اختبارات الأحياء — الصف الثالث الثانوي — الفصل الدراسي الأول	الصف الثالث الثانوي · الفصل الدراسي الأول	بنك أسئلة ونماذج اختبار لمادة الأحياء — الصف الثالث الثانوي، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة.	بنك أسئلة ونماذج اختبار لمادة الأحياء — الصف الثالث الثانوي، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	اختبارات	DOCX	3	12	8	1	yes	published	\N	digital	2026-08-31 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
15	DESIGN-PREVIEW-13	عرض-بوربوينت-اللغة-العربية-أول-متوسط-الفصل-الثاني	عرض بوربوينت اللغة العربية — الصف الأول المتوسط — الفصل الدراسي الثاني	الصف الأول المتوسط · الفصل الدراسي الثاني	عرض تقديمي متكامل لمادة اللغة العربية — الصف الأول المتوسط، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	عرض تقديمي متكامل لمادة اللغة العربية — الصف الأول المتوسط، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة. الملف بصيغة PPTX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	عرض بوربوينت	PPTX	2	7	2	2	yes	published	\N	digital	2026-06-08 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
16	DESIGN-PREVIEW-14	أوراق-عمل-الرياضيات-أول-ابتدائي-الفصل-الأول	أوراق عمل الرياضيات — الصف الأول الابتدائي — الفصل الدراسي الأول	الصف الأول الابتدائي · الفصل الدراسي الأول	حزمة أوراق عمل وأنشطة صفّية لمادة الرياضيات — الصف الأول الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع.	حزمة أوراق عمل وأنشطة صفّية لمادة الرياضيات — الصف الأول الابتدائي، الفصل الدراسي الأول، جاهزة للطباعة والتوزيع. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	أوراق عمل	PDF	1	1	4	1	no	published	\N	digital	2026-06-15 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
17	DESIGN-PREVIEW-15	تحضير-العلوم-خامس-ابتدائي-الفصل-الثالث	تحضير العلوم — الصف الخامس الابتدائي — الفصل الدراسي الثالث	الصف الخامس الابتدائي · الفصل الدراسي الثالث	دفتر تحضير جاهز لمادة العلوم — الصف الخامس الابتدائي، الفصل الدراسي الثالث. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	دفتر تحضير جاهز لمادة العلوم — الصف الخامس الابتدائي، الفصل الدراسي الثالث. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	تحضير	DOCX	1	5	5	3	yes	published	\N	digital	2026-06-26 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
18	DESIGN-PREVIEW-16	اختبارات-اللغة-الإنجليزية-ثالث-متوسط-الفصل-الأول	اختبارات اللغة الإنجليزية — الصف الثالث المتوسط — الفصل الدراسي الأول	الصف الثالث المتوسط · الفصل الدراسي الأول	بنك أسئلة ونماذج اختبار لمادة اللغة الإنجليزية — الصف الثالث المتوسط، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة.	بنك أسئلة ونماذج اختبار لمادة اللغة الإنجليزية — الصف الثالث المتوسط، الفصل الدراسي الأول، مع توزيع الدرجات ومفتاح الإجابة. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	اختبارات	DOCX	2	9	12	1	yes	published	\N	digital	2026-07-02 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
19	DESIGN-PREVIEW-17	وسائل-تعليمية-التربية-الفنية-ثالث-ابتدائي-الفصل-الثاني	وسائل تعليمية التربية الفنية — الصف الثالث الابتدائي — الفصل الدراسي الثاني	الصف الثالث الابتدائي · الفصل الدراسي الثاني	وسائل ولوحات تعليمية لمادة التربية الفنية — الصف الثالث الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل.	وسائل ولوحات تعليمية لمادة التربية الفنية — الصف الثالث الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	وسائل تعليمية	PDF	1	3	16	2	no	published	\N	digital	2026-07-08 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
20	DESIGN-PREVIEW-18	توزيع-الكفايات-اللغوية-ثاني-ثانوي-الفصل-الأول	توزيع الكفايات اللغوية — الصف الثاني الثانوي — الفصل الدراسي الأول	الصف الثاني الثانوي · الفصل الدراسي الأول	توزيع المنهج الأسبوعي لمادة الكفايات اللغوية — الصف الثاني الثانوي، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي.	توزيع المنهج الأسبوعي لمادة الكفايات اللغوية — الصف الثاني الثانوي، الفصل الدراسي الأول، موافق لخطة الفصل الدراسي. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	توزيع	PDF	3	11	3	1	no	published	\N	digital	2026-07-16 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
21	DESIGN-PREVIEW-19	حلول-الفيزياء-ثالث-ثانوي-الفصل-الثالث	حلول الفيزياء — الصف الثالث الثانوي — الفصل الدراسي الثالث	الصف الثالث الثانوي · الفصل الدراسي الثالث	حلول تمارين وأسئلة كتاب الفيزياء — الصف الثالث الثانوي، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة.	حلول تمارين وأسئلة كتاب الفيزياء — الصف الثالث الثانوي، الفصل الدراسي الثالث، مرتّبة حسب الدروس خطوة بخطوة. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	حلول	PDF	3	12	6	3	no	published	\N	digital	2026-07-23 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
22	DESIGN-PREVIEW-20	عرض-بوربوينت-الدراسات-الإسلامية-سادس-ابتدائي-الفصل-الأول	عرض بوربوينت الدراسات الإسلامية — الصف السادس الابتدائي — الفصل الدراسي الأول	الصف السادس الابتدائي · الفصل الدراسي الأول	عرض تقديمي متكامل لمادة الدراسات الإسلامية — الصف السادس الابتدائي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	عرض تقديمي متكامل لمادة الدراسات الإسلامية — الصف السادس الابتدائي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة. الملف بصيغة PPTX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	عرض بوربوينت	PPTX	1	6	9	1	yes	published	\N	digital	2026-07-30 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
23	DESIGN-PREVIEW-21	أوراق-عمل-المهارات-الحياتية-ثاني-متوسط-الفصل-الثالث	أوراق عمل المهارات الحياتية — الصف الثاني المتوسط — الفصل الدراسي الثالث	الصف الثاني المتوسط · الفصل الدراسي الثالث	حزمة أوراق عمل وأنشطة صفّية لمادة المهارات الحياتية — الصف الثاني المتوسط، الفصل الدراسي الثالث، جاهزة للطباعة والتوزيع.	حزمة أوراق عمل وأنشطة صفّية لمادة المهارات الحياتية — الصف الثاني المتوسط، الفصل الدراسي الثالث، جاهزة للطباعة والتوزيع. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	أوراق عمل	PDF	2	8	14	3	no	published	\N	digital	2026-08-06 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:24	f
24	DESIGN-PREVIEW-22	تحضير-التفكير-الناقد-أول-ثانوي-الفصل-الثاني	تحضير التفكير الناقد — الصف الأول الثانوي — الفصل الدراسي الثاني	الصف الأول الثانوي · الفصل الدراسي الثاني	دفتر تحضير جاهز لمادة التفكير الناقد — الصف الأول الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	دفتر تحضير جاهز لمادة التفكير الناقد — الصف الأول الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	تحضير	DOCX	3	10	15	2	yes	published	\N	digital	2026-08-13 06:01:25	\N	2026-09-04 06:01:25	2026-09-04 06:01:25	f
25	DESIGN-PREVIEW-23	اختبارات-علم-البيئة-ثاني-ثانوي-الفصل-الثالث	اختبارات علم البيئة — الصف الثاني الثانوي — الفصل الدراسي الثالث	الصف الثاني الثانوي · الفصل الدراسي الثالث	بنك أسئلة ونماذج اختبار لمادة علم البيئة — الصف الثاني الثانوي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة.	بنك أسئلة ونماذج اختبار لمادة علم البيئة — الصف الثاني الثانوي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	اختبارات	DOCX	3	11	18	3	yes	published	\N	digital	2026-08-20 06:01:25	\N	2026-09-04 06:01:25	2026-09-04 06:01:25	f
26	DESIGN-PREVIEW-24	وسائل-تعليمية-التربية-البدنية-أول-ابتدائي-الفصل-الثاني	وسائل تعليمية التربية البدنية — الصف الأول الابتدائي — الفصل الدراسي الثاني	الصف الأول الابتدائي · الفصل الدراسي الثاني	وسائل ولوحات تعليمية لمادة التربية البدنية — الصف الأول الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل.	وسائل ولوحات تعليمية لمادة التربية البدنية — الصف الأول الابتدائي، الفصل الدراسي الثاني، جاهزة للطباعة والتعليق داخل الفصل. الملف بصيغة PDF جاهز للطباعة مباشرة. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	وسائل تعليمية	PDF	1	1	17	2	no	published	\N	digital	2026-08-27 06:01:25	\N	2026-09-04 06:01:25	2026-09-04 06:01:25	f
4	DESIGN-PREVIEW-02	عرض-بوربوينت-العلوم-رابع-ابتدائي-الفصل-الثاني	عرض بوربوينت العلوم — الصف الرابع الابتدائي — الفصل الدراسي الثاني	الصف الرابع الابتدائي · الفصل الدراسي الثاني	عرض تقديمي متكامل لمادة العلوم — الصف الرابع الابتدائي، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	عرض تقديمي متكامل لمادة العلوم — الصف الرابع الابتدائي، الفصل الدراسي الثاني، بشرائح مصمّمة وجاهزة للعرض داخل الحصة. الملف بصيغة PPTX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	عرض بوربوينت	PPTX	1	4	5	2	yes	published	\N	digital	2026-06-22 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:26	t
6	DESIGN-PREVIEW-04	اختبارات-الدراسات-الإسلامية-خامس-ابتدائي-الفصل-الثالث	اختبارات الدراسات الإسلامية — الصف الخامس الابتدائي — الفصل الدراسي الثالث	الصف الخامس الابتدائي · الفصل الدراسي الثالث	بنك أسئلة ونماذج اختبار لمادة الدراسات الإسلامية — الصف الخامس الابتدائي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة.	بنك أسئلة ونماذج اختبار لمادة الدراسات الإسلامية — الصف الخامس الابتدائي، الفصل الدراسي الثالث، مع توزيع الدرجات ومفتاح الإجابة. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	اختبارات	DOCX	1	5	9	3	yes	published	\N	digital	2026-07-05 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:26	t
9	DESIGN-PREVIEW-07	عرض-بوربوينت-الفيزياء-أول-ثانوي-الفصل-الأول	عرض بوربوينت الفيزياء — الصف الأول الثانوي — الفصل الدراسي الأول	الصف الأول الثانوي · الفصل الدراسي الأول	عرض تقديمي متكامل لمادة الفيزياء — الصف الأول الثانوي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.	عرض تقديمي متكامل لمادة الفيزياء — الصف الأول الثانوي، الفصل الدراسي الأول، بشرائح مصمّمة وجاهزة للعرض داخل الحصة. الملف بصيغة PPTX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	عرض بوربوينت	PPTX	3	10	6	1	yes	published	\N	digital	2026-07-27 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:26	t
13	DESIGN-PREVIEW-11	تحضير-الدراسات-الاجتماعية-ثالث-ثانوي-الفصل-الثاني	تحضير الدراسات الاجتماعية — الصف الثالث الثانوي — الفصل الدراسي الثاني	الصف الثالث الثانوي · الفصل الدراسي الثاني	دفتر تحضير جاهز لمادة الدراسات الاجتماعية — الصف الثالث الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.	دفتر تحضير جاهز لمادة الدراسات الاجتماعية — الصف الثالث الثانوي، الفصل الدراسي الثاني. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس. الملف بصيغة DOCX قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء. التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.	تحضير	DOCX	3	12	11	2	yes	published	\N	digital	2026-08-24 06:01:24	\N	2026-09-04 06:01:24	2026-09-04 06:01:26	t
\.


--
-- Data for Name: quote_requests; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.quote_requests (id, org_name, contact_name, email, phone, vat_number, product_id, seats, quote, note, notified_at, created_at, updated_at, handled_at, handled_by) FROM stdin;
1	مدارس النخبة الأهلية	نورة العتيبي	ip-1788916852517@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-09 04:20:52	2026-09-09 04:20:52	2026-09-09 04:20:52	\N	\N
2	مدارس النخبة الأهلية	نورة العتيبي	ip-1788917181025@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-09 04:26:21	2026-09-09 04:26:21	2026-09-09 04:26:21	\N	\N
3	مدارس النخبة الأهلية	نورة العتيبي	ip-1788919247696@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-09 05:00:47	2026-09-09 05:00:47	2026-09-09 05:00:47	\N	\N
4	مدارس النخبة الأهلية	نورة العتيبي	ip-1789003474851@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 04:24:35	2026-09-10 04:24:35	2026-09-10 04:24:35	\N	\N
5	مدارس النخبة الأهلية	نورة العتيبي	ip-1789003699069@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 04:28:19	2026-09-10 04:28:19	2026-09-10 04:28:19	\N	\N
6	مدارس النخبة الأهلية	نورة العتيبي	ip-1789004055672@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 04:34:15	2026-09-10 04:34:15	2026-09-10 04:34:15	\N	\N
7	منشأة اختبار 1789004623349	نورة العتيبي	qr-1789004623349@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 04:43:43	2026-09-10 04:43:43	2026-09-10 04:43:44	2026-09-10 04:43:44	1
8	منشأة اختبار 1789004687651	نورة العتيبي	qr-1789004687651@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 04:44:47	2026-09-10 04:44:47	2026-09-10 04:44:48	2026-09-10 04:44:48	1
9	مدارس النخبة الأهلية	نورة العتيبي	ip-1789005006228@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 04:50:06	2026-09-10 04:50:06	2026-09-10 04:50:06	\N	\N
10	مدارس النخبة الأهلية	نورة العتيبي	ip-1789005541393@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 04:59:01	2026-09-10 04:59:01	2026-09-10 04:59:01	\N	\N
11	مدارس النخبة الأهلية	نورة العتيبي	ip-1789005645537@example.com	512345678	\N	3	50	{"product":{"id":3,"title":"\\u062a\\u062d\\u0636\\u064a\\u0631 \\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u0633\\u0627\\u062f\\u0633 \\u0627\\u0644\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u0623\\u0648\\u0644","slug":"\\u062a\\u062d\\u0636\\u064a\\u0631-\\u0627\\u0644\\u0631\\u064a\\u0627\\u0636\\u064a\\u0627\\u062a-\\u0633\\u0627\\u062f\\u0633-\\u0627\\u0628\\u062a\\u062f\\u0627\\u0626\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u0623\\u0648\\u0644","format":"DOCX","unitMinor":3500},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":175000,"discountMinor":43800,"totalMinor":131200,"currency":"SAR"}	\N	2026-09-10 05:00:45	2026-09-10 05:00:45	2026-09-10 05:00:45	\N	\N
12	مدارس النخبة الأهلية	نورة العتيبي	ip-1789005783221@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:03:03	2026-09-10 05:03:03	2026-09-10 05:03:03	\N	\N
13	مدارس النخبة الأهلية	نورة العتيبي	ip-1789006015987@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:06:56	2026-09-10 05:06:56	2026-09-10 05:06:56	\N	\N
14	مدارس النخبة الأهلية	نورة العتيبي	ip-1789006127054@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:08:47	2026-09-10 05:08:47	2026-09-10 05:08:47	\N	\N
15	مدارس النخبة الأهلية	نورة العتيبي	ip-1789006232383@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:10:32	2026-09-10 05:10:32	2026-09-10 05:10:32	\N	\N
16	مدارس النخبة الأهلية	نورة العتيبي	ip-1789006849555@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:20:49	2026-09-10 05:20:49	2026-09-10 05:20:49	\N	\N
17	مدارس النخبة الأهلية	نورة العتيبي	ip-1789007580698@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:33:00	2026-09-10 05:33:00	2026-09-10 05:33:00	\N	\N
18	منشأة اختبار 1789007658793	نورة العتيبي	qr-1789007658793@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 05:34:18	2026-09-10 05:34:18	2026-09-10 05:34:19	2026-09-10 05:34:19	1
19	مدارس النخبة الأهلية	نورة العتيبي	ip-1789008826475@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:53:46	2026-09-10 05:53:46	2026-09-10 05:53:46	\N	\N
20	مدارس النخبة الأهلية	نورة العتيبي	ip-1789008918885@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 05:55:19	2026-09-10 05:55:19	2026-09-10 05:55:19	\N	\N
22	مدارس النخبة الأهلية	نورة العتيبي	ip-1789009370360@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 06:02:50	2026-09-10 06:02:50	2026-09-10 06:02:50	\N	\N
21	منشأة اختبار 1789009004985	نورة العتيبي	qr-1789009004985@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 05:56:44	2026-09-10 05:56:44	2026-09-10 05:56:45	2026-09-10 05:56:45	1
23	مدارس النخبة الأهلية	نورة العتيبي	ip-1789009466336@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 06:04:26	2026-09-10 06:04:26	2026-09-10 06:04:26	\N	\N
24	مدارس النخبة الأهلية	نورة العتيبي	ip-1789009550644@example.com	512345678	\N	10	50	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":50,"tier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25},"nextTier":null,"subtotalMinor":160000,"discountMinor":40000,"totalMinor":120000,"currency":"SAR"}	\N	2026-09-10 06:05:50	2026-09-10 06:05:50	2026-09-10 06:05:50	\N	\N
25	منشأة اختبار 1789009624310	نورة العتيبي	qr-1789009624310@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 06:07:04	2026-09-10 06:07:04	2026-09-10 06:07:05	2026-09-10 06:07:05	1
26	منشأة اختبار 1789030182585	نورة العتيبي	qr-1789030182585@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 11:49:44	2026-09-10 11:49:42	2026-09-10 11:49:45	2026-09-10 11:49:45	1
27	منشأة اختبار 1789046102602	نورة العتيبي	qr-1789046102602@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 16:15:02	2026-09-10 16:15:02	2026-09-10 16:15:03	2026-09-10 16:15:03	1
28	منشأة اختبار 1789047661123	نورة العتيبي	qr-1789047661123@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 16:41:01	2026-09-10 16:41:01	2026-09-10 16:41:02	2026-09-10 16:41:02	1
29	منشأة اختبار 1789048040963	نورة العتيبي	qr-1789048040963@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 16:47:21	2026-09-10 16:47:20	2026-09-10 16:47:21	2026-09-10 16:47:21	1
31	منشأة اختبار 1789050042795	نورة العتيبي	qr-1789050042795@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:20:42	2026-09-10 17:20:42	2026-09-10 17:20:43	2026-09-10 17:20:43	1
30	منشأة اختبار 1789049888377	نورة العتيبي	qr-1789049888377@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:18:08	2026-09-10 17:18:08	2026-09-10 17:18:09	2026-09-10 17:18:09	1
32	منشأة اختبار 1789050221878	نورة العتيبي	qr-1789050221878@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:23:41	2026-09-10 17:23:41	2026-09-10 17:23:42	2026-09-10 17:23:42	1
33	منشأة اختبار 1789051584997	نورة العتيبي	qr-1789051584997@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:46:25	2026-09-10 17:46:25	2026-09-10 17:46:27	2026-09-10 17:46:27	1
34	منشأة اختبار 1789051758193	نورة العتيبي	qr-1789051758193@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:49:18	2026-09-10 17:49:18	2026-09-10 17:49:19	2026-09-10 17:49:19	1
35	منشأة اختبار 1789052085860	نورة العتيبي	qr-1789052085860@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 17:54:45	2026-09-10 17:54:45	2026-09-10 17:54:47	2026-09-10 17:54:47	1
37	منشأة اختبار 1789057657777	نورة العتيبي	qr-1789057657777@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 19:27:37	2026-09-10 19:27:37	2026-09-10 19:27:38	2026-09-10 19:27:38	1
36	منشأة اختبار 1789057509151	نورة العتيبي	qr-1789057509151@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 19:25:09	2026-09-10 19:25:09	2026-09-10 19:25:10	2026-09-10 19:25:10	1
38	منشأة اختبار 1789057811384	نورة العتيبي	qr-1789057811384@example.com	\N	\N	10	25	{"product":{"id":10,"title":"\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621 \\u2014 \\u0627\\u0644\\u0635\\u0641 \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u0648\\u064a \\u2014 \\u0627\\u0644\\u0641\\u0635\\u0644 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","slug":"\\u0623\\u0648\\u0631\\u0627\\u0642-\\u0639\\u0645\\u0644-\\u0627\\u0644\\u0643\\u064a\\u0645\\u064a\\u0627\\u0621-\\u062b\\u0627\\u0646\\u064a-\\u062b\\u0627\\u0646\\u0648\\u064a-\\u0627\\u0644\\u0641\\u0635\\u0644-\\u0627\\u0644\\u062b\\u0627\\u0646\\u064a","format":"PDF","unitMinor":3200},"seats":25,"tier":{"id":"school","name":"\\u0645\\u062f\\u0631\\u0633\\u0629","min":10,"max":49,"pct":15},"nextTier":{"id":"complex","name":"\\u0645\\u062c\\u0645\\u0651\\u0639","min":50,"max":null,"pct":25,"seatsToGo":25},"subtotalMinor":80000,"discountMinor":12000,"totalMinor":68000,"currency":"SAR"}	\N	2026-09-10 19:30:11	2026-09-10 19:30:11	2026-09-10 19:30:12	2026-09-10 19:30:12	1
\.


--
-- Data for Name: redirect_rules; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.redirect_rules (id, from_path, to_path, status_code, hits, created_at, updated_at, enabled, reason) FROM stdin;
\.


--
-- Data for Name: refunds; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.refunds (id, order_id, amount_minor, reason, revocation_policy, created_at, updated_at, notified_at) FROM stdin;
1	129	3200	ملاحظة داخلية للمشرف	immediate	2026-09-09 03:59:35	2026-09-09 03:59:35	2026-09-09 03:59:35
\.


--
-- Data for Name: review_aggregates; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.review_aggregates (id, product_id, avg_rating, count, distribution, recommend_pct, created_at, updated_at) FROM stdin;
1	4	4.14	7	{"5":{"count":2,"pct":29},"4":{"count":4,"pct":57},"3":{"count":1,"pct":14},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	86	2026-09-04 06:01:29	2026-09-04 06:01:29
3	9	4.73	11	{"5":{"count":8,"pct":73},"4":{"count":3,"pct":27},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
5	15	4.33	3	{"5":{"count":1,"pct":33},"4":{"count":2,"pct":67},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
6	6	3.67	6	{"5":{"count":1,"pct":17},"4":{"count":3,"pct":50},"3":{"count":1,"pct":17},"2":{"count":1,"pct":16},"1":{"count":0,"pct":0}}	67	2026-09-04 06:01:29	2026-09-04 06:01:29
10	21	4.33	3	{"5":{"count":1,"pct":33},"4":{"count":2,"pct":67},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
11	3	4.58	12	{"5":{"count":8,"pct":67},"4":{"count":3,"pct":25},"3":{"count":1,"pct":8},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	92	2026-09-04 06:01:29	2026-09-04 06:01:29
12	22	4.67	9	{"5":{"count":6,"pct":67},"4":{"count":3,"pct":33},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
13	13	4.43	7	{"5":{"count":4,"pct":57},"4":{"count":2,"pct":29},"3":{"count":1,"pct":14},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	86	2026-09-04 06:01:29	2026-09-04 06:01:29
14	5	4.78	9	{"5":{"count":7,"pct":78},"4":{"count":2,"pct":22},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
16	16	4.67	6	{"5":{"count":4,"pct":67},"4":{"count":2,"pct":33},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-04 06:01:29
4	7	4.50	2	{"5":{"count":1,"pct":50},"4":{"count":1,"pct":50},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-09 03:40:48
9	19	5.00	3	{"5":{"count":3,"pct":100},"4":{"count":0,"pct":0},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-09 03:41:06
18	8	3.40	5	{"5":{"count":1,"pct":20},"4":{"count":1,"pct":20},"3":{"count":2,"pct":40},"2":{"count":1,"pct":20},"1":{"count":0,"pct":0}}	40	2026-09-04 06:01:29	2026-09-09 03:40:48
15	18	4.00	8	{"5":{"count":3,"pct":38},"4":{"count":3,"pct":38},"3":{"count":1,"pct":12},"2":{"count":1,"pct":12},"1":{"count":0,"pct":0}}	75	2026-09-04 06:01:29	2026-09-09 03:41:06
2	10	4.00	4	{"5":{"count":1,"pct":25},"4":{"count":2,"pct":50},"3":{"count":1,"pct":25},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	75	2026-09-04 06:01:29	2026-09-09 03:41:06
17	11	5.00	1	{"5":{"count":1,"pct":100},"4":{"count":0,"pct":0},"3":{"count":0,"pct":0},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	100	2026-09-04 06:01:29	2026-09-09 03:41:06
7	12	2.40	5	{"5":{"count":0,"pct":0},"4":{"count":1,"pct":20},"3":{"count":1,"pct":20},"2":{"count":2,"pct":40},"1":{"count":1,"pct":20}}	20	2026-09-04 06:01:29	2026-09-09 03:41:06
8	24	4.00	4	{"5":{"count":1,"pct":25},"4":{"count":2,"pct":50},"3":{"count":1,"pct":25},"2":{"count":0,"pct":0},"1":{"count":0,"pct":0}}	75	2026-09-04 06:01:29	2026-09-09 03:41:45
\.


--
-- Data for Name: review_category_scores; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.review_category_scores (id, review_id, criterion, score, created_at, updated_at) FROM stdin;
1	1	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
2	1	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
3	1	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
4	1	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
5	1	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
6	1	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
7	4	content_quality	4	2026-09-04 06:01:26	2026-09-04 06:01:26
8	4	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
9	4	design_quality	4	2026-09-04 06:01:26	2026-09-04 06:01:26
10	4	worksheets_value	3	2026-09-04 06:01:26	2026-09-04 06:01:26
11	4	description_match	4	2026-09-04 06:01:26	2026-09-04 06:01:26
12	4	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
13	7	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
14	7	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
15	7	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
16	7	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
17	7	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
18	7	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
19	10	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
20	10	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
21	10	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
22	10	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
23	10	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
24	10	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
25	13	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
26	13	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
27	13	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
28	13	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
29	13	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
30	13	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
31	16	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
32	16	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
33	16	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
34	16	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
35	16	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
36	16	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
37	19	content_quality	4	2026-09-04 06:01:26	2026-09-04 06:01:26
38	19	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
39	19	design_quality	4	2026-09-04 06:01:26	2026-09-04 06:01:26
40	19	worksheets_value	3	2026-09-04 06:01:26	2026-09-04 06:01:26
41	19	description_match	4	2026-09-04 06:01:26	2026-09-04 06:01:26
42	19	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
43	20	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
44	20	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
45	20	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
46	20	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
47	20	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
48	20	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
49	23	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
50	23	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
51	23	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
52	23	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
53	23	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
54	23	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
55	26	content_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
56	26	edit_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
57	26	design_quality	5	2026-09-04 06:01:26	2026-09-04 06:01:26
58	26	worksheets_value	4	2026-09-04 06:01:26	2026-09-04 06:01:26
59	26	description_match	5	2026-09-04 06:01:26	2026-09-04 06:01:26
60	26	download_ease	5	2026-09-04 06:01:26	2026-09-04 06:01:26
61	29	content_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
62	29	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
63	29	design_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
64	29	worksheets_value	3	2026-09-04 06:01:27	2026-09-04 06:01:27
65	29	description_match	4	2026-09-04 06:01:27	2026-09-04 06:01:27
66	29	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
67	32	content_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
68	32	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
69	32	design_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
70	32	worksheets_value	3	2026-09-04 06:01:27	2026-09-04 06:01:27
71	32	description_match	4	2026-09-04 06:01:27	2026-09-04 06:01:27
72	32	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
73	35	content_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
74	35	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
75	35	design_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
76	35	worksheets_value	4	2026-09-04 06:01:27	2026-09-04 06:01:27
77	35	description_match	5	2026-09-04 06:01:27	2026-09-04 06:01:27
78	35	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
79	37	content_quality	3	2026-09-04 06:01:27	2026-09-04 06:01:27
80	37	edit_ease	4	2026-09-04 06:01:27	2026-09-04 06:01:27
81	37	design_quality	3	2026-09-04 06:01:27	2026-09-04 06:01:27
82	37	worksheets_value	2	2026-09-04 06:01:27	2026-09-04 06:01:27
83	37	description_match	3	2026-09-04 06:01:27	2026-09-04 06:01:27
84	37	download_ease	4	2026-09-04 06:01:27	2026-09-04 06:01:27
85	40	content_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
86	40	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
87	40	design_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
88	40	worksheets_value	4	2026-09-04 06:01:27	2026-09-04 06:01:27
89	40	description_match	5	2026-09-04 06:01:27	2026-09-04 06:01:27
90	40	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
91	42	content_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
92	42	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
93	42	design_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
94	42	worksheets_value	4	2026-09-04 06:01:27	2026-09-04 06:01:27
95	42	description_match	5	2026-09-04 06:01:27	2026-09-04 06:01:27
96	42	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
97	45	content_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
98	45	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
99	45	design_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
100	45	worksheets_value	4	2026-09-04 06:01:27	2026-09-04 06:01:27
101	45	description_match	5	2026-09-04 06:01:27	2026-09-04 06:01:27
102	45	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
103	48	content_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
104	48	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
105	48	design_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
106	48	worksheets_value	3	2026-09-04 06:01:27	2026-09-04 06:01:27
107	48	description_match	4	2026-09-04 06:01:27	2026-09-04 06:01:27
108	48	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
109	51	content_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
110	51	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
111	51	design_quality	5	2026-09-04 06:01:27	2026-09-04 06:01:27
112	51	worksheets_value	4	2026-09-04 06:01:27	2026-09-04 06:01:27
113	51	description_match	5	2026-09-04 06:01:27	2026-09-04 06:01:27
114	51	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
115	53	content_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
116	53	edit_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
117	53	design_quality	4	2026-09-04 06:01:27	2026-09-04 06:01:27
118	53	worksheets_value	3	2026-09-04 06:01:27	2026-09-04 06:01:27
119	53	description_match	4	2026-09-04 06:01:27	2026-09-04 06:01:27
120	53	download_ease	5	2026-09-04 06:01:27	2026-09-04 06:01:27
121	56	content_quality	3	2026-09-04 06:01:27	2026-09-04 06:01:27
122	56	edit_ease	4	2026-09-04 06:01:27	2026-09-04 06:01:27
123	56	design_quality	3	2026-09-04 06:01:27	2026-09-04 06:01:27
124	56	worksheets_value	2	2026-09-04 06:01:27	2026-09-04 06:01:27
125	56	description_match	3	2026-09-04 06:01:27	2026-09-04 06:01:27
126	56	download_ease	4	2026-09-04 06:01:27	2026-09-04 06:01:27
127	57	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
128	57	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
129	57	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
130	57	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
131	57	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
132	57	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
133	58	content_quality	2	2026-09-04 06:01:28	2026-09-04 06:01:28
134	58	edit_ease	3	2026-09-04 06:01:28	2026-09-04 06:01:28
135	58	design_quality	2	2026-09-04 06:01:28	2026-09-04 06:01:28
136	58	worksheets_value	1	2026-09-04 06:01:28	2026-09-04 06:01:28
137	58	description_match	2	2026-09-04 06:01:28	2026-09-04 06:01:28
138	58	download_ease	3	2026-09-04 06:01:28	2026-09-04 06:01:28
139	61	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
140	61	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
141	61	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
142	61	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
143	61	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
144	61	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
145	63	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
146	63	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
147	63	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
148	63	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
149	63	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
150	63	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
151	66	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
152	66	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
153	66	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
154	66	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
155	66	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
156	66	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
157	69	content_quality	3	2026-09-04 06:01:28	2026-09-04 06:01:28
158	69	edit_ease	4	2026-09-04 06:01:28	2026-09-04 06:01:28
159	69	design_quality	3	2026-09-04 06:01:28	2026-09-04 06:01:28
160	69	worksheets_value	2	2026-09-04 06:01:28	2026-09-04 06:01:28
161	69	description_match	3	2026-09-04 06:01:28	2026-09-04 06:01:28
162	69	download_ease	4	2026-09-04 06:01:28	2026-09-04 06:01:28
163	70	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
164	70	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
165	70	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
166	70	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
167	70	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
168	70	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
169	73	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
170	73	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
171	73	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
172	73	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
173	73	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
174	73	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
175	76	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
176	76	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
177	76	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
178	76	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
179	76	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
180	76	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
181	79	content_quality	3	2026-09-04 06:01:28	2026-09-04 06:01:28
182	79	edit_ease	4	2026-09-04 06:01:28	2026-09-04 06:01:28
183	79	design_quality	3	2026-09-04 06:01:28	2026-09-04 06:01:28
184	79	worksheets_value	2	2026-09-04 06:01:28	2026-09-04 06:01:28
185	79	description_match	3	2026-09-04 06:01:28	2026-09-04 06:01:28
186	79	download_ease	4	2026-09-04 06:01:28	2026-09-04 06:01:28
187	82	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
188	82	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
189	82	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
190	82	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
191	82	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
192	82	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
193	85	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
194	85	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
195	85	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
196	85	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
197	85	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
198	85	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
199	87	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
200	87	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
201	87	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
202	87	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
203	87	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
204	87	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
205	90	content_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
206	90	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
207	90	design_quality	4	2026-09-04 06:01:28	2026-09-04 06:01:28
208	90	worksheets_value	3	2026-09-04 06:01:28	2026-09-04 06:01:28
209	90	description_match	4	2026-09-04 06:01:28	2026-09-04 06:01:28
210	90	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
211	93	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
212	93	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
213	93	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
214	93	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
215	93	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
216	93	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
217	96	content_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
218	96	edit_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
219	96	design_quality	5	2026-09-04 06:01:28	2026-09-04 06:01:28
220	96	worksheets_value	4	2026-09-04 06:01:28	2026-09-04 06:01:28
221	96	description_match	5	2026-09-04 06:01:28	2026-09-04 06:01:28
222	96	download_ease	5	2026-09-04 06:01:28	2026-09-04 06:01:28
223	99	content_quality	5	2026-09-04 06:01:29	2026-09-04 06:01:29
224	99	edit_ease	5	2026-09-04 06:01:29	2026-09-04 06:01:29
225	99	design_quality	5	2026-09-04 06:01:29	2026-09-04 06:01:29
226	99	worksheets_value	4	2026-09-04 06:01:29	2026-09-04 06:01:29
227	99	description_match	5	2026-09-04 06:01:29	2026-09-04 06:01:29
228	99	download_ease	5	2026-09-04 06:01:29	2026-09-04 06:01:29
229	102	content_quality	3	2026-09-04 06:01:29	2026-09-04 06:01:29
230	102	edit_ease	4	2026-09-04 06:01:29	2026-09-04 06:01:29
231	102	design_quality	3	2026-09-04 06:01:29	2026-09-04 06:01:29
232	102	worksheets_value	2	2026-09-04 06:01:29	2026-09-04 06:01:29
233	102	description_match	3	2026-09-04 06:01:29	2026-09-04 06:01:29
234	102	download_ease	4	2026-09-04 06:01:29	2026-09-04 06:01:29
235	105	content_quality	5	2026-09-04 06:01:29	2026-09-04 06:01:29
236	105	edit_ease	5	2026-09-04 06:01:29	2026-09-04 06:01:29
237	105	design_quality	5	2026-09-04 06:01:29	2026-09-04 06:01:29
238	105	worksheets_value	4	2026-09-04 06:01:29	2026-09-04 06:01:29
239	105	description_match	5	2026-09-04 06:01:29	2026-09-04 06:01:29
240	105	download_ease	5	2026-09-04 06:01:29	2026-09-04 06:01:29
\.


--
-- Data for Name: review_media; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.review_media (id, review_id, asset_id, alt, status, "position", created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: review_replies; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.review_replies (id, review_id, body, author_label, published_at, created_at, updated_at) FROM stdin;
1	60	شكرًا لصراحتك. الحلول مرتبطة بطبعة الكتاب الحالية، وراسلنا عبر واتساب لنرسل لك النسخة المطابقة لخطة مدرستك دون رسوم.	التحاضير الحديثة	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-09-04 06:01:28
2	84	نعتذر عن عدم مطابقة التوقع. أضفنا نماذج إجابة إضافية للاختبار، والنسخة المحدّثة متاحة الآن في حسابك.	التحاضير الحديثة	2026-09-04 06:01:28	2026-09-04 06:01:28	2026-09-04 06:01:28
\.


--
-- Data for Name: review_votes; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.review_votes (id, review_id, customer_id, created_at, updated_at) FROM stdin;
1	1	14	2026-09-04 06:01:26	2026-09-04 06:01:26
2	1	13	2026-09-04 06:01:26	2026-09-04 06:01:26
3	1	12	2026-09-04 06:01:26	2026-09-04 06:01:26
4	20	14	2026-09-04 06:01:27	2026-09-04 06:01:27
5	20	13	2026-09-04 06:01:27	2026-09-04 06:01:27
6	42	14	2026-09-04 06:01:27	2026-09-04 06:01:27
7	42	13	2026-09-04 06:01:27	2026-09-04 06:01:27
8	42	12	2026-09-04 06:01:27	2026-09-04 06:01:27
9	58	14	2026-09-04 06:01:28	2026-09-04 06:01:28
10	79	14	2026-09-04 06:01:28	2026-09-04 06:01:28
11	79	13	2026-09-04 06:01:28	2026-09-04 06:01:28
12	93	14	2026-09-04 06:01:29	2026-09-04 06:01:29
13	93	13	2026-09-04 06:01:29	2026-09-04 06:01:29
14	93	12	2026-09-04 06:01:29	2026-09-04 06:01:29
\.


--
-- Data for Name: reviews; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.reviews (id, product_id, customer_id, order_id, rating, title, body, status, created_at, updated_at, variant_purchased, helpful_count, moderated_at, moderation_note) FROM stdin;
2	3	2	20	5	\N	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-20 06:01:26	2026-08-20 06:01:26	\N	0	2026-08-20 06:01:26	\N
3	3	3	21	5	وفّر عليّ وقتًا كبيرًا	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-08-13 06:01:26	2026-08-13 06:01:26	\N	0	2026-08-13 06:01:26	\N
4	3	4	22	4	\N	محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.	approved	2026-08-05 06:01:26	2026-08-05 06:01:26	\N	0	2026-08-05 06:01:26	\N
5	3	5	23	5	يستحق السعر	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-07-29 06:01:26	2026-07-29 06:01:26	\N	0	2026-07-29 06:01:26	\N
6	3	6	24	4	\N	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-07-22 06:01:26	2026-07-22 06:01:26	\N	0	2026-07-22 06:01:26	\N
7	3	7	25	5	جاهز للطباعة	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-07-14 06:01:26	2026-07-14 06:01:26	\N	0	2026-07-14 06:01:26	\N
8	3	8	26	5	\N	ملف منظّم ومرتّب، وفّر عليّ وقت التحضير كاملًا. التوزيع مطابق للخطة والأهداف واضحة.	approved	2026-07-07 06:01:26	2026-07-07 06:01:26	\N	0	2026-07-07 06:01:26	\N
9	3	9	27	3	مقبول	يحتاج مراجعة لبعض الأنشطة، وبقية المحتوى مناسب.	approved	2026-06-30 06:01:26	2026-06-30 06:01:26	\N	0	2026-06-30 06:01:26	\N
10	3	10	28	5	\N	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-06-22 06:01:26	2026-06-22 06:01:26	\N	0	2026-06-22 06:01:26	\N
11	3	11	29	4	مفيد ومرتّب	مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.	approved	2026-06-15 06:01:26	2026-06-15 06:01:26	\N	0	2026-06-15 06:01:26	\N
12	3	12	30	5	\N	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-06-07 06:01:26	2026-06-07 06:01:26	\N	0	2026-06-07 06:01:26	\N
1	3	1	19	5	جاهز للطباعة	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-28 06:01:26	2026-09-04 06:01:26	\N	3	2026-08-28 06:01:26	\N
13	4	1	31	5	يستحق السعر	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-26 06:01:26	2026-08-26 06:01:26	إصدار ١٤٤٧هـ	0	2026-08-26 06:01:26	\N
14	4	2	32	4	\N	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-08-16 06:01:26	2026-08-16 06:01:26	إصدار ١٤٤٧هـ	0	2026-08-16 06:01:26	\N
15	4	3	33	4	جيد مع ملاحظة بسيطة	محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.	approved	2026-08-07 06:01:26	2026-08-07 06:01:26	إصدار ١٤٤٧هـ	0	2026-08-07 06:01:26	\N
16	4	4	34	5	\N	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-07-29 06:01:26	2026-07-29 06:01:26	إصدار ١٤٤٧هـ	0	2026-07-29 06:01:26	\N
17	4	5	35	3	مقبول	فكرة جيدة وتنفيذ متوسط؛ استفدت من نصفه تقريبًا.	approved	2026-07-20 06:01:26	2026-07-20 06:01:26	إصدار ١٤٤٧هـ	0	2026-07-20 06:01:26	\N
18	4	6	36	4	\N	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-07-10 06:01:26	2026-07-10 06:01:26	إصدار ١٤٤٧هـ	0	2026-07-10 06:01:26	\N
19	4	7	37	4	جيد مع ملاحظة بسيطة	يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.	approved	2026-07-01 06:01:26	2026-07-01 06:01:26	إصدار ١٤٤٧هـ	0	2026-07-01 06:01:26	\N
21	5	2	39	5	\N	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-08-21 06:01:26	2026-08-21 06:01:26	\N	0	2026-08-21 06:01:26	\N
22	5	3	40	5	يستحق السعر	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-08-15 06:01:26	2026-08-15 06:01:26	\N	0	2026-08-15 06:01:26	\N
23	5	4	41	5	\N	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-08-08 06:01:26	2026-08-08 06:01:26	\N	0	2026-08-08 06:01:26	\N
24	5	5	42	5	جاهز للطباعة	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-08-01 06:01:26	2026-08-01 06:01:26	\N	0	2026-08-01 06:01:26	\N
25	5	6	43	4	\N	يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.	approved	2026-07-25 06:01:26	2026-07-25 06:01:26	\N	0	2026-07-25 06:01:26	\N
26	5	7	44	5	وفّر عليّ وقتًا كبيرًا	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-07-18 06:01:26	2026-07-18 06:01:26	\N	0	2026-07-18 06:01:26	\N
27	5	8	45	5	\N	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-07-12 06:01:27	2026-07-12 06:01:27	\N	0	2026-07-12 06:01:27	\N
28	5	9	46	4	مفيد ومرتّب	مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.	approved	2026-07-05 06:01:27	2026-07-05 06:01:27	\N	0	2026-07-05 06:01:27	\N
20	5	1	38	5	وفّر عليّ وقتًا كبيرًا	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-08-28 06:01:26	2026-09-04 06:01:27	\N	2	2026-08-28 06:01:26	\N
29	6	1	47	4	جيد مع ملاحظة بسيطة	محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.	approved	2026-08-26 06:01:27	2026-08-26 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-26 06:01:27	\N
30	6	2	48	3	\N	يحتاج مراجعة لبعض الأنشطة، وبقية المحتوى مناسب.	approved	2026-08-18 06:01:27	2026-08-18 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-18 06:01:27	\N
31	6	3	49	5	وفّر عليّ وقتًا كبيرًا	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-08-09 06:01:27	2026-08-09 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-09 06:01:27	\N
32	6	4	50	4	\N	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-07-31 06:01:27	2026-07-31 06:01:27	إصدار ١٤٤٧هـ	0	2026-07-31 06:01:27	\N
33	6	5	51	2	لم يطابق توقعاتي	المحتوى عام أكثر من اللازم ولا يغطّي دروس الوحدة الثالثة.	approved	2026-07-22 06:01:27	2026-07-22 06:01:27	إصدار ١٤٤٧هـ	0	2026-07-22 06:01:27	\N
34	6	6	52	4	\N	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-07-14 06:01:27	2026-07-14 06:01:27	إصدار ١٤٤٧هـ	0	2026-07-14 06:01:27	\N
35	7	1	53	5	يستحق السعر	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-08-17 06:01:27	2026-08-17 06:01:27	\N	0	2026-08-17 06:01:27	\N
36	7	2	54	4	\N	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-07-29 06:01:27	2026-07-29 06:01:27	\N	0	2026-07-29 06:01:27	\N
37	8	1	55	3	مقبول	فكرة جيدة وتنفيذ متوسط؛ استفدت من نصفه تقريبًا.	approved	2026-08-27 06:01:27	2026-08-27 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-27 06:01:27	\N
38	8	2	56	4	\N	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-19 06:01:27	2026-08-19 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-19 06:01:27	\N
39	8	3	57	2	لم يطابق توقعاتي	المحتوى عام أكثر من اللازم ولا يغطّي دروس الوحدة الثالثة.	approved	2026-08-11 06:01:27	2026-08-11 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-11 06:01:27	\N
40	8	4	58	5	\N	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-04 06:01:27	2026-08-04 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-04 06:01:27	\N
41	8	5	59	3	مقبول	فكرة جيدة وتنفيذ متوسط؛ استفدت من نصفه تقريبًا.	approved	2026-07-27 06:01:27	2026-07-27 06:01:27	إصدار ١٤٤٧هـ	0	2026-07-27 06:01:27	\N
43	9	2	61	5	\N	ملف منظّم ومرتّب، وفّر عليّ وقت التحضير كاملًا. التوزيع مطابق للخطة والأهداف واضحة.	approved	2026-08-28 06:01:27	2026-08-28 06:01:27	\N	0	2026-08-28 06:01:27	\N
44	9	3	62	4	مفيد ومرتّب	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-08-25 06:01:27	2026-08-25 06:01:27	\N	0	2026-08-25 06:01:27	\N
45	9	4	63	5	\N	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-22 06:01:27	2026-08-22 06:01:27	\N	0	2026-08-22 06:01:27	\N
46	9	5	64	5	يستحق السعر	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-08-19 06:01:27	2026-08-19 06:01:27	\N	0	2026-08-19 06:01:27	\N
47	9	6	65	5	\N	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-08-15 06:01:27	2026-08-15 06:01:27	\N	0	2026-08-15 06:01:27	\N
48	9	7	66	4	مفيد ومرتّب	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-12 06:01:27	2026-08-12 06:01:27	\N	0	2026-08-12 06:01:27	\N
49	9	8	67	5	\N	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-08-09 06:01:27	2026-08-09 06:01:27	\N	0	2026-08-09 06:01:27	\N
50	9	9	68	5	وفّر عليّ وقتًا كبيرًا	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-08-06 06:01:27	2026-08-06 06:01:27	\N	0	2026-08-06 06:01:27	\N
51	9	10	69	5	\N	ملف منظّم ومرتّب، وفّر عليّ وقت التحضير كاملًا. التوزيع مطابق للخطة والأهداف واضحة.	approved	2026-08-02 06:01:27	2026-08-02 06:01:27	\N	0	2026-08-02 06:01:27	\N
52	9	11	70	4	مفيد ومرتّب	مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.	approved	2026-07-30 06:01:27	2026-07-30 06:01:27	\N	0	2026-07-30 06:01:27	\N
42	9	1	60	5	جاهز للطباعة	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-09-01 06:01:27	2026-09-04 06:01:27	\N	3	2026-09-01 06:01:27	\N
53	10	1	71	4	جيد مع ملاحظة بسيطة	يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.	approved	2026-08-28 06:01:27	2026-08-28 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-28 06:01:27	\N
54	10	2	72	4	\N	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-08-22 06:01:27	2026-08-22 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-22 06:01:27	\N
55	10	3	73	5	جاهز للطباعة	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-15 06:01:27	2026-08-15 06:01:27	إصدار ١٤٤٧هـ	0	2026-08-15 06:01:27	\N
56	10	4	74	3	\N	المحتوى مختصر، وأتمنى إضافة أمثلة أكثر لكل درس.	approved	2026-08-09 06:01:28	2026-08-09 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-09 06:01:28	\N
57	11	1	75	5	وفّر عليّ وقتًا كبيرًا	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-22 06:01:28	2026-08-22 06:01:28	\N	0	2026-08-22 06:01:28	\N
59	12	2	77	3	\N	المحتوى مختصر، وأتمنى إضافة أمثلة أكثر لكل درس.	approved	2026-08-29 06:01:28	2026-08-29 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-29 06:01:28	\N
58	12	1	76	2	لم يطابق توقعاتي	التنسيق يحتاج عملًا، واضطررت لإعادة ترتيب أغلب الصفحات.	approved	2026-09-01 06:01:28	2026-09-04 06:01:28	إصدار ١٤٤٧هـ	1	2026-09-01 06:01:28	\N
60	12	3	78	1	لم يناسب خطتنا	الملف لم يناسب خطة مدرستنا إطلاقًا ولم أستفد منه.	approved	2026-08-26 06:01:28	2026-08-26 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-26 06:01:28	\N
61	12	4	79	4	\N	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-23 06:01:28	2026-08-23 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-23 06:01:28	\N
62	12	5	80	2	لم يطابق توقعاتي	المحتوى عام أكثر من اللازم ولا يغطّي دروس الوحدة الثالثة.	approved	2026-08-20 06:01:28	2026-08-20 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-20 06:01:28	\N
63	13	1	81	5	يستحق السعر	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-09-03 06:01:28	2026-09-03 06:01:28	\N	0	2026-09-03 06:01:28	\N
64	13	2	82	4	\N	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-09-01 06:01:28	2026-09-01 06:01:28	\N	0	2026-09-01 06:01:28	\N
65	13	3	83	5	جاهز للطباعة	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-08-31 06:01:28	2026-08-31 06:01:28	\N	0	2026-08-31 06:01:28	\N
66	13	4	84	4	\N	يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.	approved	2026-08-29 06:01:28	2026-08-29 06:01:28	\N	0	2026-08-29 06:01:28	\N
67	13	5	85	5	وفّر عليّ وقتًا كبيرًا	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-08-28 06:01:28	2026-08-28 06:01:28	\N	0	2026-08-28 06:01:28	\N
68	13	6	86	5	\N	ملف منظّم ومرتّب، وفّر عليّ وقت التحضير كاملًا. التوزيع مطابق للخطة والأهداف واضحة.	approved	2026-08-27 06:01:28	2026-08-27 06:01:28	\N	0	2026-08-27 06:01:28	\N
69	13	7	87	3	مقبول	يحتاج مراجعة لبعض الأنشطة، وبقية المحتوى مناسب.	approved	2026-08-25 06:01:28	2026-08-25 06:01:28	\N	0	2026-08-25 06:01:28	\N
70	15	1	88	4	مفيد ومرتّب	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-13 06:01:28	2026-08-13 06:01:28	\N	0	2026-08-13 06:01:28	\N
71	15	2	89	5	\N	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-07-22 06:01:28	2026-07-22 06:01:28	\N	0	2026-07-22 06:01:28	\N
72	15	3	90	4	مفيد ومرتّب	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-06-30 06:01:28	2026-06-30 06:01:28	\N	0	2026-06-30 06:01:28	\N
73	16	1	91	5	يستحق السعر	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-08-23 06:01:28	2026-08-23 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-23 06:01:28	\N
74	16	2	92	5	\N	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-08-12 06:01:28	2026-08-12 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-12 06:01:28	\N
75	16	3	93	4	جيد مع ملاحظة بسيطة	محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.	approved	2026-07-31 06:01:28	2026-07-31 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-31 06:01:28	\N
76	16	4	94	4	\N	مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.	approved	2026-07-20 06:01:28	2026-07-20 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-20 06:01:28	\N
77	16	5	95	5	وفّر عليّ وقتًا كبيرًا	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-07-08 06:01:28	2026-07-08 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-08 06:01:28	\N
78	16	6	96	5	\N	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-06-27 06:01:28	2026-06-27 06:01:28	إصدار ١٤٤٧هـ	0	2026-06-27 06:01:28	\N
80	18	2	98	5	\N	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-21 06:01:28	2026-08-21 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-21 06:01:28	\N
81	18	3	99	4	جيد مع ملاحظة بسيطة	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-08-14 06:01:28	2026-08-14 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-14 06:01:28	\N
82	18	4	100	4	\N	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-07 06:01:28	2026-08-07 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-07 06:01:28	\N
83	18	5	101	5	يستحق السعر	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-07-30 06:01:28	2026-07-30 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-30 06:01:28	\N
84	18	6	102	2	\N	لم يطابق توقعاتي؛ عدد الصفحات أقل مما يحتاجه الصف عندنا.	approved	2026-07-23 06:01:28	2026-07-23 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-23 06:01:28	\N
85	18	7	103	4	جيد مع ملاحظة بسيطة	محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.	approved	2026-07-16 06:01:28	2026-07-16 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-16 06:01:28	\N
86	18	8	104	5	\N	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-07-09 06:01:28	2026-07-09 06:01:28	إصدار ١٤٤٧هـ	0	2026-07-09 06:01:28	\N
79	18	1	97	3	مقبول	الملف مقبول ويؤدي الغرض، لكنه أقل تفصيلًا مما توقعت.	approved	2026-08-28 06:01:28	2026-09-04 06:01:28	إصدار ١٤٤٧هـ	2	2026-08-28 06:01:28	\N
87	19	1	105	5	يستحق السعر	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-20 06:01:28	2026-08-20 06:01:28	\N	0	2026-08-20 06:01:28	\N
88	19	2	106	5	\N	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-06 06:01:28	2026-08-06 06:01:28	\N	0	2026-08-06 06:01:28	\N
89	19	3	107	5	جاهز للطباعة	المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.	approved	2026-07-22 06:01:28	2026-07-22 06:01:28	\N	0	2026-07-22 06:01:28	\N
90	21	1	108	4	مفيد ومرتّب	التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.	approved	2026-08-24 06:01:28	2026-08-24 06:01:28	\N	0	2026-08-24 06:01:28	\N
91	21	2	109	4	\N	يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.	approved	2026-08-13 06:01:28	2026-08-13 06:01:28	\N	0	2026-08-13 06:01:28	\N
92	21	3	110	5	وفّر عليّ وقتًا كبيرًا	جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.	approved	2026-08-03 06:01:28	2026-08-03 06:01:28	\N	0	2026-08-03 06:01:28	\N
94	22	2	112	4	\N	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-08-28 06:01:28	2026-08-28 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-28 06:01:28	\N
95	22	3	113	5	جاهز للطباعة	ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.	approved	2026-08-24 06:01:28	2026-08-24 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-24 06:01:28	\N
96	22	4	114	5	\N	محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.	approved	2026-08-21 06:01:28	2026-08-21 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-21 06:01:28	\N
97	22	5	115	4	جيد مع ملاحظة بسيطة	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-08-17 06:01:28	2026-08-17 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-17 06:01:28	\N
98	22	6	116	5	\N	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-13 06:01:28	2026-08-13 06:01:28	إصدار ١٤٤٧هـ	0	2026-08-13 06:01:28	\N
99	22	7	117	5	يستحق السعر	الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.	approved	2026-08-10 06:01:29	2026-08-10 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-10 06:01:29	\N
100	22	8	118	4	\N	استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.	approved	2026-08-06 06:01:29	2026-08-06 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-06 06:01:29	\N
101	22	9	119	5	جاهز للطباعة	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-08-03 06:01:29	2026-08-03 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-03 06:01:29	\N
93	22	1	111	5	يستحق السعر	أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.	approved	2026-08-31 06:01:28	2026-09-04 06:01:29	إصدار ١٤٤٧هـ	3	2026-08-31 06:01:28	\N
102	24	1	120	3	مقبول	فكرة جيدة وتنفيذ متوسط؛ استفدت من نصفه تقريبًا.	approved	2026-08-31 06:01:29	2026-08-31 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-31 06:01:29	\N
103	24	2	121	4	\N	مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.	approved	2026-08-26 06:01:29	2026-08-26 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-26 06:01:29	\N
104	24	3	122	4	جيد مع ملاحظة بسيطة	ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.	approved	2026-08-22 06:01:29	2026-08-22 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-22 06:01:29	\N
105	24	4	123	5	\N	استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.	approved	2026-08-17 06:01:29	2026-08-17 06:01:29	إصدار ١٤٤٧هـ	0	2026-08-17 06:01:29	\N
\.


--
-- Data for Name: search_index_state; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.search_index_state (id, engine, index_name, generation, last_built_at, status, created_at, updated_at) FROM stdin;
1	meilisearch	products_v1	1	2026-09-10 11:37:21	ready	2026-09-10 11:36:48	2026-09-10 11:37:21
\.


--
-- Data for Name: search_queries; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.search_queries (id, raw_query, normalized_query, results_count, zero_result, clicked_product_id, "position", created_at, session_hash, provider, degraded, degraded_reason, latency_ms) FROM stdin;
1			26	f	\N	\N	2026-09-04 06:10:27	\N	unknown	f	\N	\N
2	رياضيات	رياضيات	3	f	\N	\N	2026-09-04 07:18:15	\N	unknown	f	\N	\N
3			26	f	\N	\N	2026-09-04 08:56:12	\N	unknown	f	\N	\N
4	رياضيات	رياضيات	3	f	\N	\N	2026-09-04 08:56:28	\N	unknown	f	\N	\N
5			26	f	\N	\N	2026-09-04 09:19:00	\N	unknown	f	\N	\N
6			26	f	\N	\N	2026-09-04 09:22:14	\N	unknown	f	\N	\N
7			26	f	\N	\N	2026-09-04 09:25:27	\N	unknown	f	\N	\N
8			26	f	\N	\N	2026-09-04 09:27:53	\N	unknown	f	\N	\N
9			26	f	\N	\N	2026-09-04 09:27:53	\N	unknown	f	\N	\N
10			26	f	\N	\N	2026-09-04 09:29:37	\N	unknown	f	\N	\N
11			26	f	\N	\N	2026-09-04 09:35:17	\N	unknown	f	\N	\N
12			26	f	\N	\N	2026-09-04 09:44:54	\N	unknown	f	\N	\N
13			26	f	\N	\N	2026-09-04 09:44:54	\N	unknown	f	\N	\N
14			26	f	\N	\N	2026-09-04 09:47:14	\N	unknown	f	\N	\N
15			26	f	\N	\N	2026-09-04 09:48:30	\N	unknown	f	\N	\N
16			26	f	\N	\N	2026-09-04 09:51:15	\N	unknown	f	\N	\N
17			26	f	\N	\N	2026-09-04 09:55:15	\N	unknown	f	\N	\N
18			26	f	\N	\N	2026-09-04 09:59:22	\N	unknown	f	\N	\N
19			26	f	\N	\N	2026-09-04 10:03:23	\N	unknown	f	\N	\N
20			26	f	\N	\N	2026-09-04 14:23:19	\N	unknown	f	\N	\N
21			26	f	\N	\N	2026-09-04 14:23:19	\N	unknown	f	\N	\N
22			26	f	\N	\N	2026-09-04 14:26:33	\N	unknown	f	\N	\N
23			26	f	\N	\N	2026-09-04 14:27:41	\N	unknown	f	\N	\N
24			26	f	\N	\N	2026-09-04 14:34:34	\N	unknown	f	\N	\N
25			26	f	\N	\N	2026-09-04 14:34:36	\N	unknown	f	\N	\N
26			26	f	\N	\N	2026-09-04 16:28:03	\N	unknown	f	\N	\N
27			26	f	\N	\N	2026-09-04 16:28:03	\N	unknown	f	\N	\N
28			26	f	\N	\N	2026-09-04 16:30:50	\N	unknown	f	\N	\N
29			26	f	\N	\N	2026-09-04 16:30:50	\N	unknown	f	\N	\N
30			26	f	\N	\N	2026-09-04 16:33:25	\N	unknown	f	\N	\N
31			26	f	\N	\N	2026-09-04 16:33:25	\N	unknown	f	\N	\N
32			26	f	\N	\N	2026-09-04 16:35:00	\N	unknown	f	\N	\N
33			26	f	\N	\N	2026-09-04 16:35:00	\N	unknown	f	\N	\N
34			26	f	\N	\N	2026-09-04 16:46:16	\N	unknown	f	\N	\N
35			26	f	\N	\N	2026-09-04 16:47:06	\N	unknown	f	\N	\N
36			26	f	\N	\N	2026-09-04 16:47:07	\N	unknown	f	\N	\N
37			26	f	\N	\N	2026-09-04 16:59:17	\N	unknown	f	\N	\N
38			26	f	\N	\N	2026-09-04 16:59:17	\N	unknown	f	\N	\N
39			26	f	\N	\N	2026-09-04 17:01:52	\N	unknown	f	\N	\N
40			26	f	\N	\N	2026-09-04 17:01:52	\N	unknown	f	\N	\N
41			26	f	\N	\N	2026-09-04 17:11:24	\N	unknown	f	\N	\N
42			26	f	\N	\N	2026-09-04 17:11:24	\N	unknown	f	\N	\N
43			26	f	\N	\N	2026-09-04 17:12:54	\N	unknown	f	\N	\N
44			26	f	\N	\N	2026-09-04 17:12:54	\N	unknown	f	\N	\N
45			26	f	\N	\N	2026-09-04 17:36:08	\N	unknown	f	\N	\N
46			26	f	\N	\N	2026-09-04 17:36:08	\N	unknown	f	\N	\N
47			26	f	\N	\N	2026-09-04 17:56:47	\N	unknown	f	\N	\N
48			26	f	\N	\N	2026-09-04 17:56:47	\N	unknown	f	\N	\N
49			26	f	\N	\N	2026-09-04 18:02:48	\N	unknown	f	\N	\N
50			26	f	\N	\N	2026-09-04 18:02:48	\N	unknown	f	\N	\N
51			26	f	\N	\N	2026-09-04 18:08:31	\N	unknown	f	\N	\N
52			26	f	\N	\N	2026-09-04 18:08:31	\N	unknown	f	\N	\N
53			26	f	\N	\N	2026-09-04 18:09:45	\N	unknown	f	\N	\N
54			26	f	\N	\N	2026-09-04 18:09:45	\N	unknown	f	\N	\N
55			26	f	\N	\N	2026-09-04 18:13:09	\N	unknown	f	\N	\N
56			26	f	\N	\N	2026-09-04 18:13:10	\N	unknown	f	\N	\N
57			26	f	\N	\N	2026-09-05 07:31:19	\N	unknown	f	\N	\N
58			26	f	\N	\N	2026-09-06 03:50:47	\N	unknown	f	\N	\N
59			26	f	\N	\N	2026-09-06 03:50:47	\N	unknown	f	\N	\N
60			26	f	\N	\N	2026-09-06 05:01:47	\N	unknown	f	\N	\N
61			26	f	\N	\N	2026-09-06 05:01:47	\N	unknown	f	\N	\N
62			26	f	\N	\N	2026-09-06 06:58:08	\N	unknown	f	\N	\N
63			26	f	\N	\N	2026-09-06 07:00:10	\N	unknown	f	\N	\N
64			26	f	\N	\N	2026-09-06 07:00:10	\N	unknown	f	\N	\N
65			26	f	\N	\N	2026-09-06 07:00:46	\N	unknown	f	\N	\N
66	الأحياء	الاحياء	1	f	\N	\N	2026-09-06 07:01:02	\N	unknown	f	\N	\N
67			26	f	\N	\N	2026-09-06 07:01:12	\N	unknown	f	\N	\N
68			26	f	\N	\N	2026-09-06 07:04:40	\N	unknown	f	\N	\N
69			26	f	\N	\N	2026-09-06 07:06:26	\N	unknown	f	\N	\N
70			26	f	\N	\N	2026-09-06 07:13:24	\N	unknown	f	\N	\N
71			26	f	\N	\N	2026-09-06 07:15:15	\N	unknown	f	\N	\N
72			26	f	\N	\N	2026-09-06 07:16:46	\N	unknown	f	\N	\N
73			26	f	\N	\N	2026-09-06 07:29:22	\N	unknown	f	\N	\N
74			26	f	\N	\N	2026-09-08 22:04:05	\N	unknown	f	\N	\N
75			26	f	\N	\N	2026-09-08 22:04:06	\N	unknown	f	\N	\N
76			10	f	\N	\N	2026-09-08 22:04:10	\N	unknown	f	\N	\N
77			6	f	\N	\N	2026-09-08 22:04:10	\N	unknown	f	\N	\N
78			8	f	\N	\N	2026-09-08 22:04:10	\N	unknown	f	\N	\N
79			1	f	\N	\N	2026-09-08 22:04:14	\N	unknown	f	\N	\N
80			1	f	\N	\N	2026-09-08 22:04:16	\N	unknown	f	\N	\N
81			1	f	\N	\N	2026-09-08 22:04:17	\N	unknown	f	\N	\N
82			3	f	\N	\N	2026-09-08 22:04:18	\N	unknown	f	\N	\N
83			2	f	\N	\N	2026-09-08 22:04:19	\N	unknown	f	\N	\N
84			2	f	\N	\N	2026-09-08 22:04:20	\N	unknown	f	\N	\N
85			1	f	\N	\N	2026-09-08 22:04:21	\N	unknown	f	\N	\N
86			1	f	\N	\N	2026-09-08 22:04:22	\N	unknown	f	\N	\N
87			26	f	\N	\N	2026-09-08 22:05:16	\N	unknown	f	\N	\N
88			26	f	\N	\N	2026-09-08 22:05:26	\N	unknown	f	\N	\N
89			10	f	\N	\N	2026-09-08 22:05:28	\N	unknown	f	\N	\N
90			6	f	\N	\N	2026-09-08 22:05:28	\N	unknown	f	\N	\N
91			8	f	\N	\N	2026-09-08 22:05:28	\N	unknown	f	\N	\N
92			1	f	\N	\N	2026-09-08 22:05:30	\N	unknown	f	\N	\N
93			1	f	\N	\N	2026-09-08 22:05:30	\N	unknown	f	\N	\N
94			1	f	\N	\N	2026-09-08 22:05:30	\N	unknown	f	\N	\N
95			3	f	\N	\N	2026-09-08 22:05:30	\N	unknown	f	\N	\N
96			2	f	\N	\N	2026-09-08 22:05:30	\N	unknown	f	\N	\N
97			2	f	\N	\N	2026-09-08 22:05:31	\N	unknown	f	\N	\N
98			1	f	\N	\N	2026-09-08 22:05:31	\N	unknown	f	\N	\N
99			1	f	\N	\N	2026-09-08 22:05:31	\N	unknown	f	\N	\N
100			26	f	\N	\N	2026-09-08 22:14:12	\N	unknown	f	\N	\N
101			26	f	\N	\N	2026-09-08 22:14:24	\N	unknown	f	\N	\N
102			10	f	\N	\N	2026-09-08 22:14:29	\N	unknown	f	\N	\N
103			6	f	\N	\N	2026-09-08 22:14:30	\N	unknown	f	\N	\N
104			8	f	\N	\N	2026-09-08 22:14:31	\N	unknown	f	\N	\N
105			1	f	\N	\N	2026-09-08 22:14:37	\N	unknown	f	\N	\N
106			1	f	\N	\N	2026-09-08 22:14:38	\N	unknown	f	\N	\N
107			1	f	\N	\N	2026-09-08 22:14:39	\N	unknown	f	\N	\N
108			3	f	\N	\N	2026-09-08 22:14:41	\N	unknown	f	\N	\N
109			2	f	\N	\N	2026-09-08 22:14:41	\N	unknown	f	\N	\N
110			2	f	\N	\N	2026-09-08 22:14:42	\N	unknown	f	\N	\N
111			1	f	\N	\N	2026-09-08 22:14:42	\N	unknown	f	\N	\N
112			1	f	\N	\N	2026-09-08 22:14:43	\N	unknown	f	\N	\N
113			26	f	\N	\N	2026-09-08 22:15:29	\N	unknown	f	\N	\N
114			26	f	\N	\N	2026-09-08 22:15:30	\N	unknown	f	\N	\N
115			26	f	\N	\N	2026-09-08 22:16:41	\N	unknown	f	\N	\N
116			26	f	\N	\N	2026-09-08 22:16:52	\N	unknown	f	\N	\N
117			10	f	\N	\N	2026-09-08 22:16:54	\N	unknown	f	\N	\N
118			6	f	\N	\N	2026-09-08 22:16:54	\N	unknown	f	\N	\N
119			8	f	\N	\N	2026-09-08 22:16:54	\N	unknown	f	\N	\N
120			1	f	\N	\N	2026-09-08 22:16:56	\N	unknown	f	\N	\N
121			1	f	\N	\N	2026-09-08 22:16:56	\N	unknown	f	\N	\N
122			1	f	\N	\N	2026-09-08 22:16:56	\N	unknown	f	\N	\N
123			3	f	\N	\N	2026-09-08 22:16:56	\N	unknown	f	\N	\N
124			2	f	\N	\N	2026-09-08 22:16:56	\N	unknown	f	\N	\N
125			2	f	\N	\N	2026-09-08 22:16:57	\N	unknown	f	\N	\N
126			1	f	\N	\N	2026-09-08 22:16:57	\N	unknown	f	\N	\N
127			1	f	\N	\N	2026-09-08 22:16:57	\N	unknown	f	\N	\N
128			26	f	\N	\N	2026-09-08 22:17:45	\N	unknown	f	\N	\N
129			26	f	\N	\N	2026-09-08 22:17:55	\N	unknown	f	\N	\N
130			10	f	\N	\N	2026-09-08 22:17:58	\N	unknown	f	\N	\N
131			6	f	\N	\N	2026-09-08 22:17:59	\N	unknown	f	\N	\N
132			8	f	\N	\N	2026-09-08 22:18:00	\N	unknown	f	\N	\N
133			1	f	\N	\N	2026-09-08 22:18:03	\N	unknown	f	\N	\N
134			1	f	\N	\N	2026-09-08 22:18:03	\N	unknown	f	\N	\N
135			1	f	\N	\N	2026-09-08 22:18:03	\N	unknown	f	\N	\N
136			3	f	\N	\N	2026-09-08 22:18:04	\N	unknown	f	\N	\N
137			2	f	\N	\N	2026-09-08 22:18:04	\N	unknown	f	\N	\N
138			2	f	\N	\N	2026-09-08 22:18:04	\N	unknown	f	\N	\N
139			1	f	\N	\N	2026-09-08 22:18:04	\N	unknown	f	\N	\N
140			1	f	\N	\N	2026-09-08 22:18:05	\N	unknown	f	\N	\N
141			26	f	\N	\N	2026-09-08 22:18:49	\N	unknown	f	\N	\N
142			26	f	\N	\N	2026-09-08 22:19:48	\N	unknown	f	\N	\N
143			10	f	\N	\N	2026-09-08 22:19:55	\N	unknown	f	\N	\N
144			6	f	\N	\N	2026-09-08 22:19:56	\N	unknown	f	\N	\N
145			8	f	\N	\N	2026-09-08 22:19:57	\N	unknown	f	\N	\N
146			26	f	\N	\N	2026-09-08 22:20:03	\N	unknown	f	\N	\N
147			1	f	\N	\N	2026-09-08 22:20:03	\N	unknown	f	\N	\N
148			1	f	\N	\N	2026-09-08 22:20:04	\N	unknown	f	\N	\N
149			1	f	\N	\N	2026-09-08 22:20:05	\N	unknown	f	\N	\N
150			3	f	\N	\N	2026-09-08 22:20:06	\N	unknown	f	\N	\N
151			2	f	\N	\N	2026-09-08 22:20:06	\N	unknown	f	\N	\N
152			2	f	\N	\N	2026-09-08 22:20:08	\N	unknown	f	\N	\N
153			1	f	\N	\N	2026-09-08 22:20:09	\N	unknown	f	\N	\N
154			1	f	\N	\N	2026-09-08 22:20:10	\N	unknown	f	\N	\N
155			26	f	\N	\N	2026-09-08 22:20:57	\N	unknown	f	\N	\N
156			10	f	\N	\N	2026-09-08 22:21:02	\N	unknown	f	\N	\N
157			6	f	\N	\N	2026-09-08 22:21:03	\N	unknown	f	\N	\N
158			8	f	\N	\N	2026-09-08 22:21:03	\N	unknown	f	\N	\N
159			26	f	\N	\N	2026-09-08 22:21:08	\N	unknown	f	\N	\N
160			1	f	\N	\N	2026-09-08 22:21:08	\N	unknown	f	\N	\N
161			1	f	\N	\N	2026-09-08 22:21:09	\N	unknown	f	\N	\N
162			1	f	\N	\N	2026-09-08 22:21:09	\N	unknown	f	\N	\N
163			3	f	\N	\N	2026-09-08 22:21:10	\N	unknown	f	\N	\N
164			2	f	\N	\N	2026-09-08 22:21:11	\N	unknown	f	\N	\N
165			2	f	\N	\N	2026-09-08 22:21:11	\N	unknown	f	\N	\N
166			1	f	\N	\N	2026-09-08 22:21:12	\N	unknown	f	\N	\N
167			26	f	\N	\N	2026-09-08 22:22:00	\N	unknown	f	\N	\N
168			1	f	\N	\N	2026-09-08 22:22:13	\N	unknown	f	\N	\N
169			26	f	\N	\N	2026-09-08 22:22:22	\N	unknown	f	\N	\N
170			10	f	\N	\N	2026-09-08 22:22:41	\N	unknown	f	\N	\N
171			6	f	\N	\N	2026-09-08 22:22:42	\N	unknown	f	\N	\N
172			8	f	\N	\N	2026-09-08 22:22:43	\N	unknown	f	\N	\N
173			1	f	\N	\N	2026-09-08 22:22:47	\N	unknown	f	\N	\N
174			1	f	\N	\N	2026-09-08 22:22:49	\N	unknown	f	\N	\N
175			1	f	\N	\N	2026-09-08 22:22:50	\N	unknown	f	\N	\N
176			3	f	\N	\N	2026-09-08 22:22:50	\N	unknown	f	\N	\N
177			2	f	\N	\N	2026-09-08 22:22:51	\N	unknown	f	\N	\N
178			2	f	\N	\N	2026-09-08 22:22:51	\N	unknown	f	\N	\N
179			1	f	\N	\N	2026-09-08 22:22:51	\N	unknown	f	\N	\N
180			1	f	\N	\N	2026-09-08 22:23:17	\N	unknown	f	\N	\N
181			26	f	\N	\N	2026-09-08 22:23:57	\N	unknown	f	\N	\N
182			26	f	\N	\N	2026-09-08 22:24:38	\N	unknown	f	\N	\N
183			10	f	\N	\N	2026-09-08 22:24:42	\N	unknown	f	\N	\N
184			6	f	\N	\N	2026-09-08 22:24:42	\N	unknown	f	\N	\N
185			8	f	\N	\N	2026-09-08 22:24:43	\N	unknown	f	\N	\N
186			1	f	\N	\N	2026-09-08 22:24:45	\N	unknown	f	\N	\N
187			1	f	\N	\N	2026-09-08 22:24:45	\N	unknown	f	\N	\N
188			1	f	\N	\N	2026-09-08 22:24:45	\N	unknown	f	\N	\N
189			3	f	\N	\N	2026-09-08 22:24:46	\N	unknown	f	\N	\N
190			2	f	\N	\N	2026-09-08 22:24:46	\N	unknown	f	\N	\N
191			2	f	\N	\N	2026-09-08 22:24:47	\N	unknown	f	\N	\N
192			1	f	\N	\N	2026-09-08 22:24:47	\N	unknown	f	\N	\N
193			1	f	\N	\N	2026-09-08 22:24:49	\N	unknown	f	\N	\N
194			26	f	\N	\N	2026-09-08 22:25:17	\N	unknown	f	\N	\N
195			26	f	\N	\N	2026-09-08 22:26:11	\N	unknown	f	\N	\N
196			10	f	\N	\N	2026-09-08 22:26:13	\N	unknown	f	\N	\N
197			6	f	\N	\N	2026-09-08 22:26:14	\N	unknown	f	\N	\N
198			8	f	\N	\N	2026-09-08 22:26:14	\N	unknown	f	\N	\N
199			1	f	\N	\N	2026-09-08 22:26:16	\N	unknown	f	\N	\N
200			1	f	\N	\N	2026-09-08 22:26:16	\N	unknown	f	\N	\N
201			1	f	\N	\N	2026-09-08 22:26:16	\N	unknown	f	\N	\N
202			3	f	\N	\N	2026-09-08 22:26:17	\N	unknown	f	\N	\N
203			2	f	\N	\N	2026-09-08 22:26:17	\N	unknown	f	\N	\N
204			2	f	\N	\N	2026-09-08 22:26:17	\N	unknown	f	\N	\N
205			1	f	\N	\N	2026-09-08 22:26:17	\N	unknown	f	\N	\N
206			1	f	\N	\N	2026-09-08 22:26:18	\N	unknown	f	\N	\N
207			26	f	\N	\N	2026-09-08 22:31:03	\N	unknown	f	\N	\N
208			26	f	\N	\N	2026-09-08 22:31:43	\N	unknown	f	\N	\N
209			10	f	\N	\N	2026-09-08 22:31:47	\N	unknown	f	\N	\N
210			6	f	\N	\N	2026-09-08 22:31:48	\N	unknown	f	\N	\N
211			8	f	\N	\N	2026-09-08 22:31:48	\N	unknown	f	\N	\N
212			1	f	\N	\N	2026-09-08 22:31:54	\N	unknown	f	\N	\N
213			1	f	\N	\N	2026-09-08 22:31:55	\N	unknown	f	\N	\N
214			1	f	\N	\N	2026-09-08 22:31:55	\N	unknown	f	\N	\N
215			3	f	\N	\N	2026-09-08 22:31:55	\N	unknown	f	\N	\N
216			2	f	\N	\N	2026-09-08 22:31:55	\N	unknown	f	\N	\N
217			2	f	\N	\N	2026-09-08 22:31:56	\N	unknown	f	\N	\N
218			1	f	\N	\N	2026-09-08 22:31:56	\N	unknown	f	\N	\N
219			1	f	\N	\N	2026-09-08 22:31:56	\N	unknown	f	\N	\N
220			26	f	\N	\N	2026-09-08 22:32:24	\N	unknown	f	\N	\N
221			26	f	\N	\N	2026-09-08 22:33:16	\N	unknown	f	\N	\N
222			10	f	\N	\N	2026-09-08 22:33:18	\N	unknown	f	\N	\N
223			6	f	\N	\N	2026-09-08 22:33:19	\N	unknown	f	\N	\N
224			8	f	\N	\N	2026-09-08 22:33:19	\N	unknown	f	\N	\N
225			1	f	\N	\N	2026-09-08 22:33:20	\N	unknown	f	\N	\N
226			1	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
227			1	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
228			3	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
229			2	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
230			2	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
231			1	f	\N	\N	2026-09-08 22:33:21	\N	unknown	f	\N	\N
232			1	f	\N	\N	2026-09-08 22:33:22	\N	unknown	f	\N	\N
233	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:15	\N	unknown	f	\N	\N
234	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:25	\N	unknown	f	\N	\N
235	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:33	\N	unknown	f	\N	\N
236	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:37	\N	unknown	f	\N	\N
237	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:38	\N	unknown	f	\N	\N
238	علوم	علوم	2	f	\N	\N	2026-09-08 23:12:39	\N	unknown	f	\N	\N
239	اختبارات الأحياء	اختبارات الاحياء	1	f	\N	\N	2026-09-08 23:14:59	\N	unknown	f	\N	\N
240	اختبارات الأحياء ثالث ثانوي	اختبارات الاحياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:16:53	\N	unknown	f	\N	\N
241	أوراق عمل الكيمياء	اوراق عمل الكيمياء	1	f	\N	\N	2026-09-08 23:16:54	\N	unknown	f	\N	\N
242	حلول الرياضيات ثالث متوسط	حلول الرياضيات ثالث متوسط	1	f	\N	\N	2026-09-08 23:16:55	\N	unknown	f	\N	\N
243	بوربوينت الدراسات الإسلامية	بوربوينت الدراسات الاسلاميه	1	f	\N	\N	2026-09-08 23:16:56	\N	unknown	f	\N	\N
244	وسائل تعليمية القرآن الكريم	وسايل تعليميه القران الكريم	1	f	\N	\N	2026-09-08 23:16:57	\N	unknown	f	\N	\N
245	تحضير التفكير الناقد	تحضير التفكير الناقد	1	f	\N	\N	2026-09-08 23:16:58	\N	unknown	f	\N	\N
246	اختبارات علم البيئة	اختبارات علم البييه	1	f	\N	\N	2026-09-08 23:17:00	\N	unknown	f	\N	\N
247	أوراق عمل المهارات الحياتية	اوراق عمل المهارات الحياتيه	1	f	\N	\N	2026-09-08 23:17:01	\N	unknown	f	\N	\N
248	تحضير الدراسات الاجتماعية	تحضير الدراسات الاجتماعيه	1	f	\N	\N	2026-09-08 23:17:02	\N	unknown	f	\N	\N
249	وسائل تعليمية التربية البدنية	وسايل تعليميه التربيه البدنيه	1	f	\N	\N	2026-09-08 23:17:04	\N	unknown	f	\N	\N
250	الأحياء	الاحياء	1	f	\N	\N	2026-09-08 23:17:05	\N	unknown	f	\N	\N
251	الكيمياء	الكيمياء	1	f	\N	\N	2026-09-08 23:17:06	\N	unknown	f	\N	\N
252	الرياضيات	الرياضيات	3	f	\N	\N	2026-09-08 23:17:07	\N	unknown	f	\N	\N
253	القرآن الكريم	القران الكريم	1	f	\N	\N	2026-09-08 23:17:08	\N	unknown	f	\N	\N
254	الصف الثالث الثانوي	الصف الثالث الثانوي	3	f	\N	\N	2026-09-08 23:17:09	\N	unknown	f	\N	\N
255	ثاني ثانوي	ثاني ثانوي	3	f	\N	\N	2026-09-08 23:17:11	\N	unknown	f	\N	\N
256	ابتدائي	ابتدايي	10	f	\N	\N	2026-09-08 23:17:12	\N	unknown	f	\N	\N
257	متوسط	متوسط	6	f	\N	\N	2026-09-08 23:17:13	\N	unknown	f	\N	\N
258	بوربوينت	بوربوينت	4	f	\N	\N	2026-09-08 23:17:14	\N	unknown	f	\N	\N
259	اختبارات	اختبارات	4	f	\N	\N	2026-09-08 23:17:15	\N	unknown	f	\N	\N
260	أوراق عمل	اوراق عمل	4	f	\N	\N	2026-09-08 23:17:16	\N	unknown	f	\N	\N
261	تحضير	تحضير	5	f	\N	\N	2026-09-08 23:17:17	\N	unknown	f	\N	\N
262	الاحياء	الاحياء	1	f	\N	\N	2026-09-08 23:17:19	\N	unknown	f	\N	\N
263	كيميا	كيميا	1	f	\N	\N	2026-09-08 23:17:20	\N	unknown	f	\N	\N
264	رياضيات	رياضيات	3	f	\N	\N	2026-09-08 23:17:21	\N	unknown	f	\N	\N
265	علم البيئه	علم البييه	1	f	\N	\N	2026-09-08 23:17:22	\N	unknown	f	\N	\N
266	فيزياء	فيزياء	2	f	\N	\N	2026-09-08 23:17:23	\N	unknown	f	\N	\N
267	لغة إنجليزية	لغه انجليزيه	3	f	\N	\N	2026-09-08 23:17:24	\N	unknown	f	\N	\N
268	xyzqwerty	xyzqwerty	0	t	\N	\N	2026-09-08 23:17:26	\N	unknown	f	\N	\N
269	اختبارات الأحياء ثالث ثانوي	اختبارات الاحياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:17:35	\N	unknown	f	\N	\N
270	أوراق عمل الكيمياء	اوراق عمل الكيمياء	1	f	\N	\N	2026-09-08 23:17:36	\N	unknown	f	\N	\N
271	حلول الرياضيات ثالث متوسط	حلول الرياضيات ثالث متوسط	1	f	\N	\N	2026-09-08 23:17:37	\N	unknown	f	\N	\N
272	بوربوينت الدراسات الإسلامية	بوربوينت الدراسات الاسلاميه	1	f	\N	\N	2026-09-08 23:17:38	\N	unknown	f	\N	\N
273	وسائل تعليمية القرآن الكريم	وسايل تعليميه القران الكريم	1	f	\N	\N	2026-09-08 23:17:39	\N	unknown	f	\N	\N
274	تحضير التفكير الناقد	تحضير التفكير الناقد	1	f	\N	\N	2026-09-08 23:17:41	\N	unknown	f	\N	\N
275	اختبارات علم البيئة	اختبارات علم البييه	1	f	\N	\N	2026-09-08 23:17:42	\N	unknown	f	\N	\N
276	أوراق عمل المهارات الحياتية	اوراق عمل المهارات الحياتيه	1	f	\N	\N	2026-09-08 23:17:43	\N	unknown	f	\N	\N
277	تحضير الدراسات الاجتماعية	تحضير الدراسات الاجتماعيه	1	f	\N	\N	2026-09-08 23:17:44	\N	unknown	f	\N	\N
278	وسائل تعليمية التربية البدنية	وسايل تعليميه التربيه البدنيه	1	f	\N	\N	2026-09-08 23:17:45	\N	unknown	f	\N	\N
279	الأحياء	الاحياء	1	f	\N	\N	2026-09-08 23:17:46	\N	unknown	f	\N	\N
280	الكيمياء	الكيمياء	1	f	\N	\N	2026-09-08 23:17:48	\N	unknown	f	\N	\N
281	الرياضيات	الرياضيات	3	f	\N	\N	2026-09-08 23:17:49	\N	unknown	f	\N	\N
282	القرآن الكريم	القران الكريم	1	f	\N	\N	2026-09-08 23:17:50	\N	unknown	f	\N	\N
283	الصف الثالث الثانوي	الصف الثالث الثانوي	3	f	\N	\N	2026-09-08 23:17:51	\N	unknown	f	\N	\N
284	ثاني ثانوي	ثاني ثانوي	3	f	\N	\N	2026-09-08 23:17:52	\N	unknown	f	\N	\N
285	ابتدائي	ابتدايي	10	f	\N	\N	2026-09-08 23:17:53	\N	unknown	f	\N	\N
286	متوسط	متوسط	6	f	\N	\N	2026-09-08 23:17:54	\N	unknown	f	\N	\N
287	بوربوينت	بوربوينت	4	f	\N	\N	2026-09-08 23:17:56	\N	unknown	f	\N	\N
288	اختبارات	اختبارات	4	f	\N	\N	2026-09-08 23:17:57	\N	unknown	f	\N	\N
289	أوراق عمل	اوراق عمل	4	f	\N	\N	2026-09-08 23:17:58	\N	unknown	f	\N	\N
290	تحضير	تحضير	5	f	\N	\N	2026-09-08 23:17:59	\N	unknown	f	\N	\N
291	الاحياء	الاحياء	1	f	\N	\N	2026-09-08 23:18:00	\N	unknown	f	\N	\N
292	كيميا	كيميا	1	f	\N	\N	2026-09-08 23:18:01	\N	unknown	f	\N	\N
293	رياضيات	رياضيات	3	f	\N	\N	2026-09-08 23:18:02	\N	unknown	f	\N	\N
294	علم البيئه	علم البييه	1	f	\N	\N	2026-09-08 23:18:04	\N	unknown	f	\N	\N
295	فيزياء	فيزياء	2	f	\N	\N	2026-09-08 23:18:05	\N	unknown	f	\N	\N
296	لغة إنجليزية	لغه انجليزيه	3	f	\N	\N	2026-09-08 23:18:06	\N	unknown	f	\N	\N
297	xyzqwerty	xyzqwerty	0	t	\N	\N	2026-09-08 23:18:07	\N	unknown	f	\N	\N
298	فيزياء	فيزياء	2	f	\N	\N	2026-09-08 23:18:36	\N	unknown	f	\N	\N
299	لغة إنجليزية	لغه انجليزيه	3	f	\N	\N	2026-09-08 23:18:37	\N	unknown	f	\N	\N
300			26	f	\N	\N	2026-09-08 23:18:38	\N	unknown	f	\N	\N
301			26	f	\N	\N	2026-09-08 23:18:49	\N	unknown	f	\N	\N
302	اختبارات الأحياء ثالث ثانوي	اختبارات الاحياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:19:32	\N	unknown	f	\N	\N
303	أوراق عمل الكيمياء	اوراق عمل الكيمياء	1	f	\N	\N	2026-09-08 23:19:33	\N	unknown	f	\N	\N
304	حلول الرياضيات ثالث متوسط	حلول الرياضيات ثالث متوسط	1	f	\N	\N	2026-09-08 23:19:34	\N	unknown	f	\N	\N
305	بوربوينت الدراسات الإسلامية	بوربوينت الدراسات الاسلاميه	1	f	\N	\N	2026-09-08 23:19:35	\N	unknown	f	\N	\N
306	وسائل تعليمية القرآن الكريم	وسايل تعليميه القران الكريم	1	f	\N	\N	2026-09-08 23:19:36	\N	unknown	f	\N	\N
307	تحضير التفكير الناقد	تحضير التفكير الناقد	1	f	\N	\N	2026-09-08 23:19:37	\N	unknown	f	\N	\N
308	اختبارات علم البيئة	اختبارات علم البييه	1	f	\N	\N	2026-09-08 23:19:38	\N	unknown	f	\N	\N
309	أوراق عمل المهارات الحياتية	اوراق عمل المهارات الحياتيه	1	f	\N	\N	2026-09-08 23:19:40	\N	unknown	f	\N	\N
310	تحضير الدراسات الاجتماعية	تحضير الدراسات الاجتماعيه	1	f	\N	\N	2026-09-08 23:19:41	\N	unknown	f	\N	\N
311	وسائل تعليمية التربية البدنية	وسايل تعليميه التربيه البدنيه	1	f	\N	\N	2026-09-08 23:19:42	\N	unknown	f	\N	\N
312	عرض بوربوينت العلوم رابع ابتدائي	عرض بوربوينت العلوم رابع ابتدايي	1	f	\N	\N	2026-09-08 23:19:43	\N	unknown	f	\N	\N
313	أوراق عمل لغتي	اوراق عمل لغتي	1	f	\N	\N	2026-09-08 23:19:44	\N	unknown	f	\N	\N
314	توزيع المهارات الرقمية	توزيع المهارات الرقميه	1	f	\N	\N	2026-09-08 23:19:45	\N	unknown	f	\N	\N
315	حلول الفيزياء ثالث ثانوي	حلول الفيزياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:19:47	\N	unknown	f	\N	\N
316	توزيع الكفايات اللغوية	توزيع الكفايات اللغويه	1	f	\N	\N	2026-09-08 23:19:48	\N	unknown	f	\N	\N
317	الأحياء	الاحياء	1	f	\N	\N	2026-09-08 23:19:49	\N	unknown	f	\N	\N
318	الكيمياء	الكيمياء	1	f	\N	\N	2026-09-08 23:19:50	\N	unknown	f	\N	\N
319	الرياضيات	الرياضيات	3	f	\N	\N	2026-09-08 23:19:51	\N	unknown	f	\N	\N
320	القرآن الكريم	القران الكريم	1	f	\N	\N	2026-09-08 23:19:52	\N	unknown	f	\N	\N
321	الفيزياء	الفيزياء	2	f	\N	\N	2026-09-08 23:19:54	\N	unknown	f	\N	\N
322	اللغة الإنجليزية	اللغه الانجليزيه	2	f	\N	\N	2026-09-08 23:19:55	\N	unknown	f	\N	\N
323	العلوم	العلوم	2	f	\N	\N	2026-09-08 23:19:56	\N	unknown	f	\N	\N
324	الدراسات الإسلامية	الدراسات الاسلاميه	2	f	\N	\N	2026-09-08 23:19:57	\N	unknown	f	\N	\N
325	الصف الثالث الثانوي	الصف الثالث الثانوي	3	f	\N	\N	2026-09-08 23:19:58	\N	unknown	f	\N	\N
326	ثاني ثانوي	ثاني ثانوي	3	f	\N	\N	2026-09-08 23:20:00	\N	unknown	f	\N	\N
327	ثالث متوسط	ثالث متوسط	2	f	\N	\N	2026-09-08 23:20:01	\N	unknown	f	\N	\N
328	ابتدائي	ابتدايي	10	f	\N	\N	2026-09-08 23:20:02	\N	unknown	f	\N	\N
329	متوسط	متوسط	6	f	\N	\N	2026-09-08 23:20:03	\N	unknown	f	\N	\N
330	بوربوينت	بوربوينت	4	f	\N	\N	2026-09-08 23:20:04	\N	unknown	f	\N	\N
331	اختبارات	اختبارات	4	f	\N	\N	2026-09-08 23:20:05	\N	unknown	f	\N	\N
332	أوراق عمل	اوراق عمل	4	f	\N	\N	2026-09-08 23:20:07	\N	unknown	f	\N	\N
333	تحضير	تحضير	5	f	\N	\N	2026-09-08 23:20:08	\N	unknown	f	\N	\N
334	توزيع	توزيع	2	f	\N	\N	2026-09-08 23:20:09	\N	unknown	f	\N	\N
335	الاحياء	الاحياء	1	f	\N	\N	2026-09-08 23:20:10	\N	unknown	f	\N	\N
336	كيميا	كيميا	1	f	\N	\N	2026-09-08 23:20:11	\N	unknown	f	\N	\N
337	رياضيات	رياضيات	3	f	\N	\N	2026-09-08 23:20:12	\N	unknown	f	\N	\N
338	علم البيئه	علم البييه	1	f	\N	\N	2026-09-08 23:20:14	\N	unknown	f	\N	\N
339	فيزياء	فيزياء	2	f	\N	\N	2026-09-08 23:20:15	\N	unknown	f	\N	\N
340	انجليزي	انجليزي	2	f	\N	\N	2026-09-08 23:20:16	\N	unknown	f	\N	\N
341	تاريخ	تاريخ	0	t	\N	\N	2026-09-08 23:20:17	\N	unknown	f	\N	\N
342	xyzqwerty	xyzqwerty	0	t	\N	\N	2026-09-08 23:20:18	\N	unknown	f	\N	\N
343	اختبارات الأحياء ثالث ثانوي	اختبارات الاحياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:20:22	\N	unknown	f	\N	\N
344	أوراق عمل الكيمياء	اوراق عمل الكيمياء	1	f	\N	\N	2026-09-08 23:20:23	\N	unknown	f	\N	\N
345	حلول الرياضيات ثالث متوسط	حلول الرياضيات ثالث متوسط	1	f	\N	\N	2026-09-08 23:20:25	\N	unknown	f	\N	\N
346	بوربوينت الدراسات الإسلامية	بوربوينت الدراسات الاسلاميه	1	f	\N	\N	2026-09-08 23:20:26	\N	unknown	f	\N	\N
347	وسائل تعليمية القرآن الكريم	وسايل تعليميه القران الكريم	1	f	\N	\N	2026-09-08 23:20:27	\N	unknown	f	\N	\N
348	تحضير التفكير الناقد	تحضير التفكير الناقد	1	f	\N	\N	2026-09-08 23:20:28	\N	unknown	f	\N	\N
349	اختبارات علم البيئة	اختبارات علم البييه	1	f	\N	\N	2026-09-08 23:20:29	\N	unknown	f	\N	\N
350	أوراق عمل المهارات الحياتية	اوراق عمل المهارات الحياتيه	1	f	\N	\N	2026-09-08 23:20:31	\N	unknown	f	\N	\N
351	تحضير الدراسات الاجتماعية	تحضير الدراسات الاجتماعيه	1	f	\N	\N	2026-09-08 23:20:32	\N	unknown	f	\N	\N
352	وسائل تعليمية التربية البدنية	وسايل تعليميه التربيه البدنيه	1	f	\N	\N	2026-09-08 23:20:33	\N	unknown	f	\N	\N
353	عرض بوربوينت العلوم رابع ابتدائي	عرض بوربوينت العلوم رابع ابتدايي	1	f	\N	\N	2026-09-08 23:20:34	\N	unknown	f	\N	\N
354	أوراق عمل لغتي	اوراق عمل لغتي	1	f	\N	\N	2026-09-08 23:20:35	\N	unknown	f	\N	\N
355	توزيع المهارات الرقمية	توزيع المهارات الرقميه	1	f	\N	\N	2026-09-08 23:20:36	\N	unknown	f	\N	\N
356	حلول الفيزياء ثالث ثانوي	حلول الفيزياء ثالث ثانوي	1	f	\N	\N	2026-09-08 23:20:38	\N	unknown	f	\N	\N
357	توزيع الكفايات اللغوية	توزيع الكفايات اللغويه	1	f	\N	\N	2026-09-08 23:20:39	\N	unknown	f	\N	\N
358	الأحياء	الاحياء	1	f	\N	\N	2026-09-08 23:20:40	\N	unknown	f	\N	\N
359	الكيمياء	الكيمياء	1	f	\N	\N	2026-09-08 23:20:41	\N	unknown	f	\N	\N
360	الرياضيات	الرياضيات	3	f	\N	\N	2026-09-08 23:20:42	\N	unknown	f	\N	\N
361	القرآن الكريم	القران الكريم	1	f	\N	\N	2026-09-08 23:20:44	\N	unknown	f	\N	\N
362	الفيزياء	الفيزياء	2	f	\N	\N	2026-09-08 23:20:45	\N	unknown	f	\N	\N
363	اللغة الإنجليزية	اللغه الانجليزيه	2	f	\N	\N	2026-09-08 23:20:46	\N	unknown	f	\N	\N
364	العلوم	العلوم	2	f	\N	\N	2026-09-08 23:20:47	\N	unknown	f	\N	\N
365	الدراسات الإسلامية	الدراسات الاسلاميه	2	f	\N	\N	2026-09-08 23:20:48	\N	unknown	f	\N	\N
366	الصف الثالث الثانوي	الصف الثالث الثانوي	3	f	\N	\N	2026-09-08 23:20:49	\N	unknown	f	\N	\N
367	ثاني ثانوي	ثاني ثانوي	3	f	\N	\N	2026-09-08 23:20:50	\N	unknown	f	\N	\N
368	ثالث متوسط	ثالث متوسط	2	f	\N	\N	2026-09-08 23:20:52	\N	unknown	f	\N	\N
369	ابتدائي	ابتدايي	10	f	\N	\N	2026-09-08 23:20:53	\N	unknown	f	\N	\N
370	متوسط	متوسط	6	f	\N	\N	2026-09-08 23:20:54	\N	unknown	f	\N	\N
371	بوربوينت	بوربوينت	4	f	\N	\N	2026-09-08 23:20:55	\N	unknown	f	\N	\N
372	اختبارات	اختبارات	4	f	\N	\N	2026-09-08 23:20:56	\N	unknown	f	\N	\N
373	أوراق عمل	اوراق عمل	4	f	\N	\N	2026-09-08 23:20:57	\N	unknown	f	\N	\N
374	تحضير	تحضير	5	f	\N	\N	2026-09-08 23:20:58	\N	unknown	f	\N	\N
375	توزيع	توزيع	2	f	\N	\N	2026-09-08 23:21:00	\N	unknown	f	\N	\N
376	الاحياء	الاحياء	1	f	\N	\N	2026-09-08 23:21:01	\N	unknown	f	\N	\N
377	كيميا	كيميا	1	f	\N	\N	2026-09-08 23:21:02	\N	unknown	f	\N	\N
378	رياضيات	رياضيات	3	f	\N	\N	2026-09-08 23:21:03	\N	unknown	f	\N	\N
379	علم البيئه	علم البييه	1	f	\N	\N	2026-09-08 23:21:04	\N	unknown	f	\N	\N
380	فيزياء	فيزياء	2	f	\N	\N	2026-09-08 23:21:05	\N	unknown	f	\N	\N
381	انجليزي	انجليزي	2	f	\N	\N	2026-09-08 23:21:06	\N	unknown	f	\N	\N
382	تاريخ	تاريخ	0	t	\N	\N	2026-09-08 23:21:08	\N	unknown	f	\N	\N
383	xyzqwerty	xyzqwerty	0	t	\N	\N	2026-09-08 23:21:09	\N	unknown	f	\N	\N
384			26	f	\N	\N	2026-09-09 00:08:01	\N	unknown	f	\N	\N
385			26	f	\N	\N	2026-09-09 00:11:13	\N	unknown	f	\N	\N
386			26	f	\N	\N	2026-09-09 00:13:24	\N	unknown	f	\N	\N
387			26	f	\N	\N	2026-09-09 00:14:35	\N	unknown	f	\N	\N
388			26	f	\N	\N	2026-09-09 00:15:39	\N	unknown	f	\N	\N
389			26	f	\N	\N	2026-09-09 00:15:40	\N	unknown	f	\N	\N
390			10	f	\N	\N	2026-09-09 00:15:42	\N	unknown	f	\N	\N
391			6	f	\N	\N	2026-09-09 00:15:43	\N	unknown	f	\N	\N
392			8	f	\N	\N	2026-09-09 00:15:43	\N	unknown	f	\N	\N
393			1	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
394			1	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
395			1	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
396			3	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
397			2	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
398			2	f	\N	\N	2026-09-09 00:15:46	\N	unknown	f	\N	\N
399			1	f	\N	\N	2026-09-09 00:15:47	\N	unknown	f	\N	\N
400			1	f	\N	\N	2026-09-09 00:15:47	\N	unknown	f	\N	\N
401			26	f	\N	\N	2026-09-09 00:16:54	\N	unknown	f	\N	\N
402			26	f	\N	\N	2026-09-09 00:17:04	\N	unknown	f	\N	\N
403			10	f	\N	\N	2026-09-09 00:17:07	\N	unknown	f	\N	\N
404			6	f	\N	\N	2026-09-09 00:17:08	\N	unknown	f	\N	\N
405			8	f	\N	\N	2026-09-09 00:17:08	\N	unknown	f	\N	\N
406			1	f	\N	\N	2026-09-09 00:17:12	\N	unknown	f	\N	\N
407			1	f	\N	\N	2026-09-09 00:17:12	\N	unknown	f	\N	\N
408			1	f	\N	\N	2026-09-09 00:17:12	\N	unknown	f	\N	\N
409			3	f	\N	\N	2026-09-09 00:17:12	\N	unknown	f	\N	\N
410			2	f	\N	\N	2026-09-09 00:17:13	\N	unknown	f	\N	\N
411			2	f	\N	\N	2026-09-09 00:17:13	\N	unknown	f	\N	\N
412			1	f	\N	\N	2026-09-09 00:17:13	\N	unknown	f	\N	\N
413			1	f	\N	\N	2026-09-09 00:17:13	\N	unknown	f	\N	\N
414			26	f	\N	\N	2026-09-09 00:18:38	\N	unknown	f	\N	\N
415			26	f	\N	\N	2026-09-09 00:19:46	\N	unknown	f	\N	\N
416			26	f	\N	\N	2026-09-09 00:21:07	\N	unknown	f	\N	\N
417			26	f	\N	\N	2026-09-09 00:22:46	\N	unknown	f	\N	\N
418			26	f	\N	\N	2026-09-09 00:23:49	\N	unknown	f	\N	\N
419			26	f	\N	\N	2026-09-09 00:49:07	\N	unknown	f	\N	\N
420			26	f	\N	\N	2026-09-09 00:50:59	\N	unknown	f	\N	\N
421			26	f	\N	\N	2026-09-09 00:52:36	\N	unknown	f	\N	\N
422			26	f	\N	\N	2026-09-09 00:54:03	\N	unknown	f	\N	\N
423			26	f	\N	\N	2026-09-09 00:54:38	\N	unknown	f	\N	\N
424			10	f	\N	\N	2026-09-09 00:54:41	\N	unknown	f	\N	\N
425			6	f	\N	\N	2026-09-09 00:54:42	\N	unknown	f	\N	\N
426			8	f	\N	\N	2026-09-09 00:54:42	\N	unknown	f	\N	\N
427			1	f	\N	\N	2026-09-09 00:54:45	\N	unknown	f	\N	\N
428			1	f	\N	\N	2026-09-09 00:54:46	\N	unknown	f	\N	\N
429			1	f	\N	\N	2026-09-09 00:54:46	\N	unknown	f	\N	\N
430			3	f	\N	\N	2026-09-09 00:54:47	\N	unknown	f	\N	\N
431			2	f	\N	\N	2026-09-09 00:54:48	\N	unknown	f	\N	\N
432			2	f	\N	\N	2026-09-09 00:54:48	\N	unknown	f	\N	\N
433			1	f	\N	\N	2026-09-09 00:54:48	\N	unknown	f	\N	\N
434			1	f	\N	\N	2026-09-09 00:54:49	\N	unknown	f	\N	\N
435			26	f	\N	\N	2026-09-09 00:55:18	\N	unknown	f	\N	\N
436			26	f	\N	\N	2026-09-09 00:56:11	\N	unknown	f	\N	\N
437			10	f	\N	\N	2026-09-09 00:56:12	\N	unknown	f	\N	\N
438			6	f	\N	\N	2026-09-09 00:56:12	\N	unknown	f	\N	\N
439			8	f	\N	\N	2026-09-09 00:56:13	\N	unknown	f	\N	\N
440			1	f	\N	\N	2026-09-09 00:56:14	\N	unknown	f	\N	\N
441			1	f	\N	\N	2026-09-09 00:56:14	\N	unknown	f	\N	\N
442			1	f	\N	\N	2026-09-09 00:56:15	\N	unknown	f	\N	\N
443			3	f	\N	\N	2026-09-09 00:56:15	\N	unknown	f	\N	\N
444			2	f	\N	\N	2026-09-09 00:56:15	\N	unknown	f	\N	\N
445			2	f	\N	\N	2026-09-09 00:56:15	\N	unknown	f	\N	\N
446			1	f	\N	\N	2026-09-09 00:56:16	\N	unknown	f	\N	\N
447			1	f	\N	\N	2026-09-09 00:56:16	\N	unknown	f	\N	\N
448			26	f	\N	\N	2026-09-09 01:01:27	\N	unknown	f	\N	\N
449			26	f	\N	\N	2026-09-09 01:02:01	\N	unknown	f	\N	\N
450			10	f	\N	\N	2026-09-09 01:02:05	\N	unknown	f	\N	\N
451			6	f	\N	\N	2026-09-09 01:02:06	\N	unknown	f	\N	\N
452			8	f	\N	\N	2026-09-09 01:02:07	\N	unknown	f	\N	\N
453			1	f	\N	\N	2026-09-09 01:02:08	\N	unknown	f	\N	\N
454			1	f	\N	\N	2026-09-09 01:02:08	\N	unknown	f	\N	\N
455			1	f	\N	\N	2026-09-09 01:02:09	\N	unknown	f	\N	\N
456			3	f	\N	\N	2026-09-09 01:02:09	\N	unknown	f	\N	\N
457			2	f	\N	\N	2026-09-09 01:02:09	\N	unknown	f	\N	\N
458			2	f	\N	\N	2026-09-09 01:02:09	\N	unknown	f	\N	\N
459			1	f	\N	\N	2026-09-09 01:02:10	\N	unknown	f	\N	\N
460			1	f	\N	\N	2026-09-09 01:02:10	\N	unknown	f	\N	\N
461			26	f	\N	\N	2026-09-09 01:02:39	\N	unknown	f	\N	\N
462			26	f	\N	\N	2026-09-09 01:03:29	\N	unknown	f	\N	\N
463			10	f	\N	\N	2026-09-09 01:03:31	\N	unknown	f	\N	\N
464			6	f	\N	\N	2026-09-09 01:03:31	\N	unknown	f	\N	\N
465			8	f	\N	\N	2026-09-09 01:03:32	\N	unknown	f	\N	\N
466			1	f	\N	\N	2026-09-09 01:03:34	\N	unknown	f	\N	\N
467			1	f	\N	\N	2026-09-09 01:03:34	\N	unknown	f	\N	\N
468			1	f	\N	\N	2026-09-09 01:03:35	\N	unknown	f	\N	\N
469			3	f	\N	\N	2026-09-09 01:03:35	\N	unknown	f	\N	\N
470			2	f	\N	\N	2026-09-09 01:03:35	\N	unknown	f	\N	\N
471			2	f	\N	\N	2026-09-09 01:03:35	\N	unknown	f	\N	\N
472			1	f	\N	\N	2026-09-09 01:03:36	\N	unknown	f	\N	\N
473			1	f	\N	\N	2026-09-09 01:03:36	\N	unknown	f	\N	\N
474			26	f	\N	\N	2026-09-09 01:04:20	\N	unknown	f	\N	\N
475			26	f	\N	\N	2026-09-09 01:07:23	\N	unknown	f	\N	\N
476			26	f	\N	\N	2026-09-09 01:07:47	\N	unknown	f	\N	\N
477			10	f	\N	\N	2026-09-09 01:07:49	\N	unknown	f	\N	\N
478			6	f	\N	\N	2026-09-09 01:07:49	\N	unknown	f	\N	\N
479			8	f	\N	\N	2026-09-09 01:07:50	\N	unknown	f	\N	\N
480			1	f	\N	\N	2026-09-09 01:07:51	\N	unknown	f	\N	\N
481			1	f	\N	\N	2026-09-09 01:07:52	\N	unknown	f	\N	\N
482			1	f	\N	\N	2026-09-09 01:07:52	\N	unknown	f	\N	\N
483			3	f	\N	\N	2026-09-09 01:07:52	\N	unknown	f	\N	\N
484			2	f	\N	\N	2026-09-09 01:07:53	\N	unknown	f	\N	\N
485			2	f	\N	\N	2026-09-09 01:07:53	\N	unknown	f	\N	\N
486			1	f	\N	\N	2026-09-09 01:07:54	\N	unknown	f	\N	\N
487			1	f	\N	\N	2026-09-09 01:07:54	\N	unknown	f	\N	\N
488			26	f	\N	\N	2026-09-09 01:08:27	\N	unknown	f	\N	\N
489			26	f	\N	\N	2026-09-09 01:32:39	\N	unknown	f	\N	\N
490			7	f	\N	\N	2026-09-09 01:39:48	\N	unknown	f	\N	\N
491			5	f	\N	\N	2026-09-09 01:39:49	\N	unknown	f	\N	\N
492			5	f	\N	\N	2026-09-09 01:39:50	\N	unknown	f	\N	\N
493			7	f	\N	\N	2026-09-09 01:39:51	\N	unknown	f	\N	\N
494			2	f	\N	\N	2026-09-09 01:39:53	\N	unknown	f	\N	\N
495			5	f	\N	\N	2026-09-09 01:40:36	\N	unknown	f	\N	\N
496			26	f	\N	\N	2026-09-09 01:40:56	\N	unknown	f	\N	\N
497			10	f	\N	\N	2026-09-09 01:40:58	\N	unknown	f	\N	\N
498			4	f	\N	\N	2026-09-09 01:41:00	\N	unknown	f	\N	\N
499			5	f	\N	\N	2026-09-09 01:41:04	\N	unknown	f	\N	\N
500			5	f	\N	\N	2026-09-09 01:41:41	\N	unknown	f	\N	\N
501			26	f	\N	\N	2026-09-09 01:42:04	\N	unknown	f	\N	\N
502			10	f	\N	\N	2026-09-09 01:42:06	\N	unknown	f	\N	\N
503			4	f	\N	\N	2026-09-09 01:42:08	\N	unknown	f	\N	\N
504			5	f	\N	\N	2026-09-09 01:42:10	\N	unknown	f	\N	\N
505			10	f	\N	\N	2026-09-09 01:42:24	\N	unknown	f	\N	\N
506			5	f	\N	\N	2026-09-09 01:48:55	\N	unknown	f	\N	\N
507			26	f	\N	\N	2026-09-09 01:49:52	\N	unknown	f	\N	\N
508			26	f	\N	\N	2026-09-09 01:50:42	\N	unknown	f	\N	\N
509			26	f	\N	\N	2026-09-09 01:50:58	\N	unknown	f	\N	\N
510			26	f	\N	\N	2026-09-09 01:58:55	\N	unknown	f	\N	\N
511			26	f	\N	\N	2026-09-09 02:07:38	\N	unknown	f	\N	\N
512			26	f	\N	\N	2026-09-09 02:07:38	\N	unknown	f	\N	\N
513			6	f	\N	\N	2026-09-09 02:10:04	\N	unknown	f	\N	\N
514			26	f	\N	\N	2026-09-09 02:10:15	\N	unknown	f	\N	\N
515			26	f	\N	\N	2026-09-09 02:12:06	\N	unknown	f	\N	\N
516			26	f	\N	\N	2026-09-09 02:12:06	\N	unknown	f	\N	\N
517			26	f	\N	\N	2026-09-09 02:12:37	\N	unknown	f	\N	\N
518			26	f	\N	\N	2026-09-09 02:15:08	\N	unknown	f	\N	\N
519			26	f	\N	\N	2026-09-09 02:16:58	\N	unknown	f	\N	\N
520			10	f	\N	\N	2026-09-09 02:17:25	\N	unknown	f	\N	\N
521			4	f	\N	\N	2026-09-09 02:17:27	\N	unknown	f	\N	\N
522			5	f	\N	\N	2026-09-09 02:17:30	\N	unknown	f	\N	\N
523			5	f	\N	\N	2026-09-09 02:17:32	\N	unknown	f	\N	\N
524			26	f	\N	\N	2026-09-09 02:18:16	\N	unknown	f	\N	\N
525			26	f	\N	\N	2026-09-09 02:18:27	\N	unknown	f	\N	\N
526			10	f	\N	\N	2026-09-09 02:18:30	\N	unknown	f	\N	\N
527			6	f	\N	\N	2026-09-09 02:18:30	\N	unknown	f	\N	\N
528			8	f	\N	\N	2026-09-09 02:18:30	\N	unknown	f	\N	\N
529			1	f	\N	\N	2026-09-09 02:18:33	\N	unknown	f	\N	\N
530			1	f	\N	\N	2026-09-09 02:18:34	\N	unknown	f	\N	\N
531			1	f	\N	\N	2026-09-09 02:18:34	\N	unknown	f	\N	\N
532			3	f	\N	\N	2026-09-09 02:18:34	\N	unknown	f	\N	\N
533			2	f	\N	\N	2026-09-09 02:18:34	\N	unknown	f	\N	\N
534			2	f	\N	\N	2026-09-09 02:18:35	\N	unknown	f	\N	\N
535			26	f	\N	\N	2026-09-09 02:20:02	\N	unknown	f	\N	\N
536			10	f	\N	\N	2026-09-09 02:20:04	\N	unknown	f	\N	\N
537			4	f	\N	\N	2026-09-09 02:20:06	\N	unknown	f	\N	\N
538			5	f	\N	\N	2026-09-09 02:20:09	\N	unknown	f	\N	\N
539			5	f	\N	\N	2026-09-09 02:20:11	\N	unknown	f	\N	\N
540			26	f	\N	\N	2026-09-09 02:30:31	\N	unknown	f	\N	\N
541			26	f	\N	\N	2026-09-09 03:36:19	\N	unknown	f	\N	\N
542			26	f	\N	\N	2026-09-09 03:50:37	\N	unknown	f	\N	\N
543			26	f	\N	\N	2026-09-09 03:59:59	\N	unknown	f	\N	\N
544			26	f	\N	\N	2026-09-09 04:08:46	\N	unknown	f	\N	\N
545			26	f	\N	\N	2026-09-09 04:09:57	\N	unknown	f	\N	\N
546			10	f	\N	\N	2026-09-09 04:09:59	\N	unknown	f	\N	\N
547			4	f	\N	\N	2026-09-09 04:10:02	\N	unknown	f	\N	\N
548			5	f	\N	\N	2026-09-09 04:10:04	\N	unknown	f	\N	\N
549			5	f	\N	\N	2026-09-09 04:10:07	\N	unknown	f	\N	\N
550			26	f	\N	\N	2026-09-09 04:18:43	\N	unknown	f	\N	\N
551			26	f	\N	\N	2026-09-09 04:19:56	\N	unknown	f	\N	\N
552			26	f	\N	\N	2026-09-09 04:26:08	\N	unknown	f	\N	\N
553			26	f	\N	\N	2026-09-09 04:50:56	\N	unknown	f	\N	\N
554			26	f	\N	\N	2026-09-09 04:56:37	\N	unknown	f	\N	\N
555			10	f	\N	\N	2026-09-09 04:56:48	\N	unknown	f	\N	\N
556			4	f	\N	\N	2026-09-09 04:56:51	\N	unknown	f	\N	\N
557			5	f	\N	\N	2026-09-09 04:56:53	\N	unknown	f	\N	\N
558			5	f	\N	\N	2026-09-09 04:56:55	\N	unknown	f	\N	\N
559			26	f	\N	\N	2026-09-09 04:58:05	\N	unknown	f	\N	\N
560			26	f	\N	\N	2026-09-09 04:58:16	\N	unknown	f	\N	\N
561			10	f	\N	\N	2026-09-09 04:58:18	\N	unknown	f	\N	\N
562			6	f	\N	\N	2026-09-09 04:58:18	\N	unknown	f	\N	\N
563			8	f	\N	\N	2026-09-09 04:58:19	\N	unknown	f	\N	\N
564			1	f	\N	\N	2026-09-09 04:58:21	\N	unknown	f	\N	\N
565			1	f	\N	\N	2026-09-09 04:58:21	\N	unknown	f	\N	\N
566			1	f	\N	\N	2026-09-09 04:58:22	\N	unknown	f	\N	\N
567			3	f	\N	\N	2026-09-09 04:58:22	\N	unknown	f	\N	\N
568			2	f	\N	\N	2026-09-09 04:58:22	\N	unknown	f	\N	\N
569			2	f	\N	\N	2026-09-09 04:58:22	\N	unknown	f	\N	\N
570			26	f	\N	\N	2026-09-09 05:00:42	\N	unknown	f	\N	\N
571			26	f	\N	\N	2026-09-10 02:24:49	\N	unknown	f	\N	\N
572			26	f	\N	\N	2026-09-10 04:22:49	\N	unknown	f	\N	\N
573			10	f	\N	\N	2026-09-10 04:24:01	\N	unknown	f	\N	\N
574			4	f	\N	\N	2026-09-10 04:24:03	\N	unknown	f	\N	\N
575			5	f	\N	\N	2026-09-10 04:24:11	\N	unknown	f	\N	\N
576			5	f	\N	\N	2026-09-10 04:24:13	\N	unknown	f	\N	\N
577			26	f	\N	\N	2026-09-10 04:24:20	\N	unknown	f	\N	\N
578			26	f	\N	\N	2026-09-10 04:25:16	\N	unknown	f	\N	\N
579			10	f	\N	\N	2026-09-10 04:25:18	\N	unknown	f	\N	\N
580			6	f	\N	\N	2026-09-10 04:25:19	\N	unknown	f	\N	\N
581			8	f	\N	\N	2026-09-10 04:25:20	\N	unknown	f	\N	\N
582			1	f	\N	\N	2026-09-10 04:25:22	\N	unknown	f	\N	\N
583			1	f	\N	\N	2026-09-10 04:25:22	\N	unknown	f	\N	\N
584			1	f	\N	\N	2026-09-10 04:25:23	\N	unknown	f	\N	\N
585			3	f	\N	\N	2026-09-10 04:25:23	\N	unknown	f	\N	\N
586			2	f	\N	\N	2026-09-10 04:25:23	\N	unknown	f	\N	\N
587			2	f	\N	\N	2026-09-10 04:25:24	\N	unknown	f	\N	\N
588			26	f	\N	\N	2026-09-10 04:27:00	\N	unknown	f	\N	\N
589			10	f	\N	\N	2026-09-10 04:27:49	\N	unknown	f	\N	\N
590			4	f	\N	\N	2026-09-10 04:27:51	\N	unknown	f	\N	\N
591			5	f	\N	\N	2026-09-10 04:27:54	\N	unknown	f	\N	\N
592			5	f	\N	\N	2026-09-10 04:27:56	\N	unknown	f	\N	\N
593			26	f	\N	\N	2026-09-10 04:28:06	\N	unknown	f	\N	\N
594			26	f	\N	\N	2026-09-10 04:29:00	\N	unknown	f	\N	\N
595			10	f	\N	\N	2026-09-10 04:29:02	\N	unknown	f	\N	\N
596			6	f	\N	\N	2026-09-10 04:29:02	\N	unknown	f	\N	\N
597			8	f	\N	\N	2026-09-10 04:29:02	\N	unknown	f	\N	\N
598			1	f	\N	\N	2026-09-10 04:29:04	\N	unknown	f	\N	\N
599			1	f	\N	\N	2026-09-10 04:29:04	\N	unknown	f	\N	\N
600			1	f	\N	\N	2026-09-10 04:29:04	\N	unknown	f	\N	\N
601			3	f	\N	\N	2026-09-10 04:29:04	\N	unknown	f	\N	\N
602			2	f	\N	\N	2026-09-10 04:29:05	\N	unknown	f	\N	\N
603			2	f	\N	\N	2026-09-10 04:29:05	\N	unknown	f	\N	\N
604			26	f	\N	\N	2026-09-10 04:33:07	\N	unknown	f	\N	\N
605			10	f	\N	\N	2026-09-10 04:33:44	\N	unknown	f	\N	\N
606			4	f	\N	\N	2026-09-10 04:33:47	\N	unknown	f	\N	\N
607			5	f	\N	\N	2026-09-10 04:33:49	\N	unknown	f	\N	\N
608			5	f	\N	\N	2026-09-10 04:33:51	\N	unknown	f	\N	\N
609			26	f	\N	\N	2026-09-10 04:34:11	\N	unknown	f	\N	\N
610			26	f	\N	\N	2026-09-10 04:34:57	\N	unknown	f	\N	\N
611			10	f	\N	\N	2026-09-10 04:34:59	\N	unknown	f	\N	\N
612			6	f	\N	\N	2026-09-10 04:34:59	\N	unknown	f	\N	\N
613			8	f	\N	\N	2026-09-10 04:35:00	\N	unknown	f	\N	\N
614			1	f	\N	\N	2026-09-10 04:35:01	\N	unknown	f	\N	\N
615			1	f	\N	\N	2026-09-10 04:35:01	\N	unknown	f	\N	\N
616			1	f	\N	\N	2026-09-10 04:35:02	\N	unknown	f	\N	\N
617			3	f	\N	\N	2026-09-10 04:35:02	\N	unknown	f	\N	\N
618			2	f	\N	\N	2026-09-10 04:35:02	\N	unknown	f	\N	\N
619			2	f	\N	\N	2026-09-10 04:35:02	\N	unknown	f	\N	\N
620			26	f	\N	\N	2026-09-10 04:42:49	\N	unknown	f	\N	\N
621			26	f	\N	\N	2026-09-10 04:44:18	\N	unknown	f	\N	\N
622			26	f	\N	\N	2026-09-10 04:47:17	\N	unknown	f	\N	\N
623			10	f	\N	\N	2026-09-10 04:47:29	\N	unknown	f	\N	\N
624			5	f	\N	\N	2026-09-10 04:48:30	\N	unknown	f	\N	\N
625			10	f	\N	\N	2026-09-10 04:49:31	\N	unknown	f	\N	\N
626			26	f	\N	\N	2026-09-10 04:49:37	\N	unknown	f	\N	\N
627			26	f	\N	\N	2026-09-10 04:51:08	\N	unknown	f	\N	\N
628			26	f	\N	\N	2026-09-10 04:51:43	\N	unknown	f	\N	\N
629			10	f	\N	\N	2026-09-10 04:51:47	\N	unknown	f	\N	\N
630			6	f	\N	\N	2026-09-10 04:51:47	\N	unknown	f	\N	\N
631			8	f	\N	\N	2026-09-10 04:51:47	\N	unknown	f	\N	\N
632			1	f	\N	\N	2026-09-10 04:51:49	\N	unknown	f	\N	\N
633			1	f	\N	\N	2026-09-10 04:51:50	\N	unknown	f	\N	\N
634			1	f	\N	\N	2026-09-10 04:51:50	\N	unknown	f	\N	\N
635			3	f	\N	\N	2026-09-10 04:51:50	\N	unknown	f	\N	\N
636			2	f	\N	\N	2026-09-10 04:51:50	\N	unknown	f	\N	\N
637			2	f	\N	\N	2026-09-10 04:51:51	\N	unknown	f	\N	\N
638			26	f	\N	\N	2026-09-10 04:52:51	\N	unknown	f	\N	\N
639			10	f	\N	\N	2026-09-10 04:52:52	\N	unknown	f	\N	\N
640			5	f	\N	\N	2026-09-10 04:53:53	\N	unknown	f	\N	\N
641			26	f	\N	\N	2026-09-10 04:54:13	\N	unknown	f	\N	\N
642			10	f	\N	\N	2026-09-10 04:54:54	\N	unknown	f	\N	\N
643			26	f	\N	\N	2026-09-10 04:56:38	\N	unknown	f	\N	\N
644			26	f	\N	\N	2026-09-10 04:57:59	\N	unknown	f	\N	\N
645			10	f	\N	\N	2026-09-10 04:58:28	\N	unknown	f	\N	\N
646			4	f	\N	\N	2026-09-10 04:58:30	\N	unknown	f	\N	\N
647			5	f	\N	\N	2026-09-10 04:58:32	\N	unknown	f	\N	\N
648			5	f	\N	\N	2026-09-10 04:58:34	\N	unknown	f	\N	\N
649			26	f	\N	\N	2026-09-10 04:59:06	\N	unknown	f	\N	\N
650			26	f	\N	\N	2026-09-10 04:59:48	\N	unknown	f	\N	\N
651			10	f	\N	\N	2026-09-10 04:59:51	\N	unknown	f	\N	\N
652			6	f	\N	\N	2026-09-10 04:59:51	\N	unknown	f	\N	\N
653			8	f	\N	\N	2026-09-10 04:59:51	\N	unknown	f	\N	\N
654			1	f	\N	\N	2026-09-10 04:59:54	\N	unknown	f	\N	\N
655			1	f	\N	\N	2026-09-10 04:59:54	\N	unknown	f	\N	\N
656			1	f	\N	\N	2026-09-10 04:59:54	\N	unknown	f	\N	\N
657			3	f	\N	\N	2026-09-10 04:59:54	\N	unknown	f	\N	\N
658			2	f	\N	\N	2026-09-10 04:59:55	\N	unknown	f	\N	\N
659			2	f	\N	\N	2026-09-10 04:59:55	\N	unknown	f	\N	\N
660			4	f	\N	\N	2026-09-10 05:00:13	\N	unknown	f	\N	\N
661			5	f	\N	\N	2026-09-10 05:00:16	\N	unknown	f	\N	\N
662			5	f	\N	\N	2026-09-10 05:00:21	\N	unknown	f	\N	\N
663			26	f	\N	\N	2026-09-10 05:00:28	\N	unknown	f	\N	\N
664			26	f	\N	\N	2026-09-10 05:01:27	\N	unknown	f	\N	\N
665			10	f	\N	\N	2026-09-10 05:01:29	\N	unknown	f	\N	\N
666			6	f	\N	\N	2026-09-10 05:01:30	\N	unknown	f	\N	\N
667			8	f	\N	\N	2026-09-10 05:01:30	\N	unknown	f	\N	\N
668			26	f	\N	\N	2026-09-10 05:01:32	\N	unknown	f	\N	\N
669			1	f	\N	\N	2026-09-10 05:01:32	\N	unknown	f	\N	\N
670			1	f	\N	\N	2026-09-10 05:01:33	\N	unknown	f	\N	\N
671			1	f	\N	\N	2026-09-10 05:01:33	\N	unknown	f	\N	\N
672			3	f	\N	\N	2026-09-10 05:01:34	\N	unknown	f	\N	\N
673			2	f	\N	\N	2026-09-10 05:01:34	\N	unknown	f	\N	\N
674			2	f	\N	\N	2026-09-10 05:01:34	\N	unknown	f	\N	\N
675			4	f	\N	\N	2026-09-10 05:02:32	\N	unknown	f	\N	\N
676			10	f	\N	\N	2026-09-10 05:02:34	\N	unknown	f	\N	\N
677			5	f	\N	\N	2026-09-10 05:02:35	\N	unknown	f	\N	\N
678			5	f	\N	\N	2026-09-10 05:02:37	\N	unknown	f	\N	\N
679			26	f	\N	\N	2026-09-10 05:02:45	\N	unknown	f	\N	\N
680			26	f	\N	\N	2026-09-10 05:03:55	\N	unknown	f	\N	\N
681			26	f	\N	\N	2026-09-10 05:03:56	\N	unknown	f	\N	\N
682			10	f	\N	\N	2026-09-10 05:03:59	\N	unknown	f	\N	\N
683			6	f	\N	\N	2026-09-10 05:03:59	\N	unknown	f	\N	\N
684			8	f	\N	\N	2026-09-10 05:04:02	\N	unknown	f	\N	\N
685			1	f	\N	\N	2026-09-10 05:04:04	\N	unknown	f	\N	\N
686			1	f	\N	\N	2026-09-10 05:04:05	\N	unknown	f	\N	\N
687			1	f	\N	\N	2026-09-10 05:04:05	\N	unknown	f	\N	\N
688			3	f	\N	\N	2026-09-10 05:04:06	\N	unknown	f	\N	\N
689			2	f	\N	\N	2026-09-10 05:04:06	\N	unknown	f	\N	\N
690			2	f	\N	\N	2026-09-10 05:04:07	\N	unknown	f	\N	\N
691			26	f	\N	\N	2026-09-10 05:05:11	\N	unknown	f	\N	\N
692			10	f	\N	\N	2026-09-10 05:06:18	\N	unknown	f	\N	\N
693			4	f	\N	\N	2026-09-10 05:06:20	\N	unknown	f	\N	\N
694			5	f	\N	\N	2026-09-10 05:06:23	\N	unknown	f	\N	\N
695			5	f	\N	\N	2026-09-10 05:06:25	\N	unknown	f	\N	\N
696			26	f	\N	\N	2026-09-10 05:06:32	\N	unknown	f	\N	\N
697			26	f	\N	\N	2026-09-10 05:07:39	\N	unknown	f	\N	\N
698			26	f	\N	\N	2026-09-10 05:07:40	\N	unknown	f	\N	\N
699			10	f	\N	\N	2026-09-10 05:07:42	\N	unknown	f	\N	\N
700			6	f	\N	\N	2026-09-10 05:07:43	\N	unknown	f	\N	\N
701			8	f	\N	\N	2026-09-10 05:07:44	\N	unknown	f	\N	\N
702			1	f	\N	\N	2026-09-10 05:07:47	\N	unknown	f	\N	\N
703			1	f	\N	\N	2026-09-10 05:07:47	\N	unknown	f	\N	\N
704			1	f	\N	\N	2026-09-10 05:07:48	\N	unknown	f	\N	\N
705			3	f	\N	\N	2026-09-10 05:07:48	\N	unknown	f	\N	\N
706			2	f	\N	\N	2026-09-10 05:07:48	\N	unknown	f	\N	\N
707			2	f	\N	\N	2026-09-10 05:07:48	\N	unknown	f	\N	\N
708			4	f	\N	\N	2026-09-10 05:08:15	\N	unknown	f	\N	\N
709			5	f	\N	\N	2026-09-10 05:08:19	\N	unknown	f	\N	\N
710			5	f	\N	\N	2026-09-10 05:08:21	\N	unknown	f	\N	\N
711			26	f	\N	\N	2026-09-10 05:08:47	\N	unknown	f	\N	\N
712			26	f	\N	\N	2026-09-10 05:09:30	\N	unknown	f	\N	\N
713			10	f	\N	\N	2026-09-10 05:09:33	\N	unknown	f	\N	\N
714			6	f	\N	\N	2026-09-10 05:09:34	\N	unknown	f	\N	\N
715			8	f	\N	\N	2026-09-10 05:09:34	\N	unknown	f	\N	\N
716			1	f	\N	\N	2026-09-10 05:09:36	\N	unknown	f	\N	\N
717			1	f	\N	\N	2026-09-10 05:09:36	\N	unknown	f	\N	\N
718			1	f	\N	\N	2026-09-10 05:09:37	\N	unknown	f	\N	\N
719			3	f	\N	\N	2026-09-10 05:09:37	\N	unknown	f	\N	\N
720			2	f	\N	\N	2026-09-10 05:09:39	\N	unknown	f	\N	\N
721			2	f	\N	\N	2026-09-10 05:09:39	\N	unknown	f	\N	\N
722			4	f	\N	\N	2026-09-10 05:09:58	\N	unknown	f	\N	\N
723			5	f	\N	\N	2026-09-10 05:10:01	\N	unknown	f	\N	\N
724			5	f	\N	\N	2026-09-10 05:10:03	\N	unknown	f	\N	\N
725			26	f	\N	\N	2026-09-10 05:10:09	\N	unknown	f	\N	\N
726			26	f	\N	\N	2026-09-10 05:11:16	\N	unknown	f	\N	\N
727			26	f	\N	\N	2026-09-10 05:11:17	\N	unknown	f	\N	\N
728			10	f	\N	\N	2026-09-10 05:11:20	\N	unknown	f	\N	\N
729			6	f	\N	\N	2026-09-10 05:11:20	\N	unknown	f	\N	\N
730			8	f	\N	\N	2026-09-10 05:11:21	\N	unknown	f	\N	\N
731			1	f	\N	\N	2026-09-10 05:11:27	\N	unknown	f	\N	\N
732			1	f	\N	\N	2026-09-10 05:11:27	\N	unknown	f	\N	\N
733			1	f	\N	\N	2026-09-10 05:11:27	\N	unknown	f	\N	\N
734			3	f	\N	\N	2026-09-10 05:11:28	\N	unknown	f	\N	\N
735			2	f	\N	\N	2026-09-10 05:11:28	\N	unknown	f	\N	\N
736			2	f	\N	\N	2026-09-10 05:11:28	\N	unknown	f	\N	\N
737			26	f	\N	\N	2026-09-10 05:19:56	\N	unknown	f	\N	\N
738			10	f	\N	\N	2026-09-10 05:20:08	\N	unknown	f	\N	\N
739			4	f	\N	\N	2026-09-10 05:20:11	\N	unknown	f	\N	\N
740			5	f	\N	\N	2026-09-10 05:20:14	\N	unknown	f	\N	\N
741			5	f	\N	\N	2026-09-10 05:20:16	\N	unknown	f	\N	\N
742			26	f	\N	\N	2026-09-10 05:21:20	\N	unknown	f	\N	\N
743			26	f	\N	\N	2026-09-10 05:21:31	\N	unknown	f	\N	\N
744			10	f	\N	\N	2026-09-10 05:21:33	\N	unknown	f	\N	\N
745			6	f	\N	\N	2026-09-10 05:21:37	\N	unknown	f	\N	\N
746			8	f	\N	\N	2026-09-10 05:21:37	\N	unknown	f	\N	\N
747			1	f	\N	\N	2026-09-10 05:21:40	\N	unknown	f	\N	\N
748			1	f	\N	\N	2026-09-10 05:21:40	\N	unknown	f	\N	\N
749			1	f	\N	\N	2026-09-10 05:21:41	\N	unknown	f	\N	\N
750			3	f	\N	\N	2026-09-10 05:21:41	\N	unknown	f	\N	\N
751			2	f	\N	\N	2026-09-10 05:21:42	\N	unknown	f	\N	\N
752			2	f	\N	\N	2026-09-10 05:21:42	\N	unknown	f	\N	\N
753			26	f	\N	\N	2026-09-10 05:32:21	\N	unknown	f	\N	\N
754			10	f	\N	\N	2026-09-10 05:32:31	\N	unknown	f	\N	\N
755			4	f	\N	\N	2026-09-10 05:32:36	\N	unknown	f	\N	\N
756			5	f	\N	\N	2026-09-10 05:32:38	\N	unknown	f	\N	\N
757			5	f	\N	\N	2026-09-10 05:32:40	\N	unknown	f	\N	\N
758			26	f	\N	\N	2026-09-10 05:33:26	\N	unknown	f	\N	\N
759			26	f	\N	\N	2026-09-10 05:33:36	\N	unknown	f	\N	\N
760			10	f	\N	\N	2026-09-10 05:33:38	\N	unknown	f	\N	\N
761			6	f	\N	\N	2026-09-10 05:33:39	\N	unknown	f	\N	\N
762			8	f	\N	\N	2026-09-10 05:33:39	\N	unknown	f	\N	\N
763			1	f	\N	\N	2026-09-10 05:33:40	\N	unknown	f	\N	\N
764			1	f	\N	\N	2026-09-10 05:33:41	\N	unknown	f	\N	\N
765			1	f	\N	\N	2026-09-10 05:33:41	\N	unknown	f	\N	\N
766			3	f	\N	\N	2026-09-10 05:33:42	\N	unknown	f	\N	\N
767			2	f	\N	\N	2026-09-10 05:33:42	\N	unknown	f	\N	\N
768			2	f	\N	\N	2026-09-10 05:33:42	\N	unknown	f	\N	\N
769			26	f	\N	\N	2026-09-10 05:46:47	\N	unknown	f	\N	\N
770			26	f	\N	\N	2026-09-10 05:48:37	\N	unknown	f	\N	\N
771			26	f	\N	\N	2026-09-10 05:50:49	\N	unknown	f	\N	\N
772			26	f	\N	\N	2026-09-10 05:50:49	\N	unknown	f	\N	\N
773			26	f	\N	\N	2026-09-10 05:53:02	\N	unknown	f	\N	\N
774			10	f	\N	\N	2026-09-10 05:53:14	\N	unknown	f	\N	\N
775			4	f	\N	\N	2026-09-10 05:53:16	\N	unknown	f	\N	\N
776			5	f	\N	\N	2026-09-10 05:53:18	\N	unknown	f	\N	\N
777			5	f	\N	\N	2026-09-10 05:53:20	\N	unknown	f	\N	\N
778			26	f	\N	\N	2026-09-10 05:54:17	\N	unknown	f	\N	\N
779			26	f	\N	\N	2026-09-10 05:54:27	\N	unknown	f	\N	\N
780			10	f	\N	\N	2026-09-10 05:54:29	\N	unknown	f	\N	\N
781			6	f	\N	\N	2026-09-10 05:54:29	\N	unknown	f	\N	\N
782			8	f	\N	\N	2026-09-10 05:54:29	\N	unknown	f	\N	\N
783			1	f	\N	\N	2026-09-10 05:54:31	\N	unknown	f	\N	\N
784			1	f	\N	\N	2026-09-10 05:54:31	\N	unknown	f	\N	\N
785			1	f	\N	\N	2026-09-10 05:54:31	\N	unknown	f	\N	\N
786			3	f	\N	\N	2026-09-10 05:54:31	\N	unknown	f	\N	\N
787			2	f	\N	\N	2026-09-10 05:54:32	\N	unknown	f	\N	\N
788			2	f	\N	\N	2026-09-10 05:54:32	\N	unknown	f	\N	\N
789			4	f	\N	\N	2026-09-10 05:54:51	\N	unknown	f	\N	\N
790			5	f	\N	\N	2026-09-10 05:54:53	\N	unknown	f	\N	\N
791			5	f	\N	\N	2026-09-10 05:54:55	\N	unknown	f	\N	\N
792			26	f	\N	\N	2026-09-10 05:55:19	\N	unknown	f	\N	\N
793			26	f	\N	\N	2026-09-10 05:56:00	\N	unknown	f	\N	\N
794			10	f	\N	\N	2026-09-10 05:56:01	\N	unknown	f	\N	\N
795			6	f	\N	\N	2026-09-10 05:56:02	\N	unknown	f	\N	\N
796			8	f	\N	\N	2026-09-10 05:56:02	\N	unknown	f	\N	\N
797			1	f	\N	\N	2026-09-10 05:56:06	\N	unknown	f	\N	\N
798			1	f	\N	\N	2026-09-10 05:56:06	\N	unknown	f	\N	\N
799			1	f	\N	\N	2026-09-10 05:56:07	\N	unknown	f	\N	\N
800			3	f	\N	\N	2026-09-10 05:56:07	\N	unknown	f	\N	\N
801			2	f	\N	\N	2026-09-10 05:56:07	\N	unknown	f	\N	\N
802			2	f	\N	\N	2026-09-10 05:56:07	\N	unknown	f	\N	\N
803			26	f	\N	\N	2026-09-10 06:00:58	\N	unknown	f	\N	\N
804			26	f	\N	\N	2026-09-10 06:00:58	\N	unknown	f	\N	\N
805			26	f	\N	\N	2026-09-10 06:02:12	\N	unknown	f	\N	\N
806			10	f	\N	\N	2026-09-10 06:02:22	\N	unknown	f	\N	\N
807			4	f	\N	\N	2026-09-10 06:02:24	\N	unknown	f	\N	\N
808			5	f	\N	\N	2026-09-10 06:02:26	\N	unknown	f	\N	\N
809			5	f	\N	\N	2026-09-10 06:02:28	\N	unknown	f	\N	\N
810			26	f	\N	\N	2026-09-10 06:03:20	\N	unknown	f	\N	\N
811			26	f	\N	\N	2026-09-10 06:03:29	\N	unknown	f	\N	\N
812			10	f	\N	\N	2026-09-10 06:03:31	\N	unknown	f	\N	\N
813			6	f	\N	\N	2026-09-10 06:03:31	\N	unknown	f	\N	\N
814			8	f	\N	\N	2026-09-10 06:03:31	\N	unknown	f	\N	\N
815			1	f	\N	\N	2026-09-10 06:03:34	\N	unknown	f	\N	\N
816			1	f	\N	\N	2026-09-10 06:03:34	\N	unknown	f	\N	\N
817			1	f	\N	\N	2026-09-10 06:03:34	\N	unknown	f	\N	\N
818			3	f	\N	\N	2026-09-10 06:03:35	\N	unknown	f	\N	\N
819			2	f	\N	\N	2026-09-10 06:03:35	\N	unknown	f	\N	\N
820			2	f	\N	\N	2026-09-10 06:03:35	\N	unknown	f	\N	\N
821			4	f	\N	\N	2026-09-10 06:03:57	\N	unknown	f	\N	\N
822			5	f	\N	\N	2026-09-10 06:04:00	\N	unknown	f	\N	\N
823			5	f	\N	\N	2026-09-10 06:04:01	\N	unknown	f	\N	\N
824			26	f	\N	\N	2026-09-10 06:04:21	\N	unknown	f	\N	\N
825			26	f	\N	\N	2026-09-10 06:05:05	\N	unknown	f	\N	\N
826			10	f	\N	\N	2026-09-10 06:05:07	\N	unknown	f	\N	\N
827			6	f	\N	\N	2026-09-10 06:05:07	\N	unknown	f	\N	\N
828			8	f	\N	\N	2026-09-10 06:05:07	\N	unknown	f	\N	\N
829			1	f	\N	\N	2026-09-10 06:05:08	\N	unknown	f	\N	\N
830			1	f	\N	\N	2026-09-10 06:05:09	\N	unknown	f	\N	\N
831			1	f	\N	\N	2026-09-10 06:05:09	\N	unknown	f	\N	\N
832			3	f	\N	\N	2026-09-10 06:05:09	\N	unknown	f	\N	\N
833			2	f	\N	\N	2026-09-10 06:05:09	\N	unknown	f	\N	\N
834			2	f	\N	\N	2026-09-10 06:05:09	\N	unknown	f	\N	\N
835			4	f	\N	\N	2026-09-10 06:05:24	\N	unknown	f	\N	\N
836			5	f	\N	\N	2026-09-10 06:05:26	\N	unknown	f	\N	\N
837			5	f	\N	\N	2026-09-10 06:05:28	\N	unknown	f	\N	\N
838			26	f	\N	\N	2026-09-10 06:05:34	\N	unknown	f	\N	\N
839			26	f	\N	\N	2026-09-10 06:06:30	\N	unknown	f	\N	\N
840			10	f	\N	\N	2026-09-10 06:06:31	\N	unknown	f	\N	\N
841			6	f	\N	\N	2026-09-10 06:06:31	\N	unknown	f	\N	\N
842			8	f	\N	\N	2026-09-10 06:06:31	\N	unknown	f	\N	\N
843			1	f	\N	\N	2026-09-10 06:06:32	\N	unknown	f	\N	\N
844			1	f	\N	\N	2026-09-10 06:06:32	\N	unknown	f	\N	\N
845			1	f	\N	\N	2026-09-10 06:06:32	\N	unknown	f	\N	\N
846			3	f	\N	\N	2026-09-10 06:06:32	\N	unknown	f	\N	\N
847			2	f	\N	\N	2026-09-10 06:06:32	\N	unknown	f	\N	\N
848			2	f	\N	\N	2026-09-10 06:06:33	\N	unknown	f	\N	\N
849			26	f	\N	\N	2026-09-10 06:18:39	\N	unknown	f	\N	\N
850			26	f	\N	\N	2026-09-10 06:18:39	\N	unknown	f	\N	\N
851			26	f	\N	\N	2026-09-10 06:57:32	\N	unknown	f	\N	\N
852			26	f	\N	\N	2026-09-10 07:18:37	\N	unknown	f	\N	\N
853			26	f	\N	\N	2026-09-10 07:21:00	\N	unknown	f	\N	\N
854			26	f	\N	\N	2026-09-10 07:25:39	\N	unknown	f	\N	\N
20897			26	f	\N	\N	2026-09-10 12:31:58	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	23
20898			26	f	\N	\N	2026-09-10 12:31:58	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	34
20899			26	f	\N	\N	2026-09-10 12:48:34	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	64
20900			26	f	\N	\N	2026-09-10 12:48:34	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	27
20901			26	f	\N	\N	2026-09-10 12:58:49	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	47
20902			26	f	\N	\N	2026-09-10 12:58:49	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	43
20903			26	f	\N	\N	2026-09-10 13:07:51	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	30
20904			26	f	\N	\N	2026-09-10 13:07:51	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	19
20905			26	f	\N	\N	2026-09-10 13:17:29	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	18
20906			26	f	\N	\N	2026-09-10 13:17:29	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	17
20907	تحضير	تحضير	5	f	\N	\N	2026-09-10 13:23:50	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	21
20908			26	f	\N	\N	2026-09-10 14:08:49	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	93
20909			26	f	\N	\N	2026-09-10 14:08:49	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	52
20910			26	f	\N	\N	2026-09-10 14:30:15	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	124
20911			26	f	\N	\N	2026-09-10 14:30:15	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	16
20912			26	f	\N	\N	2026-09-10 14:43:38	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	24
20913			26	f	\N	\N	2026-09-10 14:43:38	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	31
20914			26	f	\N	\N	2026-09-10 14:51:52	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	54
875			26	f	\N	\N	2026-09-10 08:10:07	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	unknown	f	\N	\N
877	ملزمة	ملزمه	0	t	\N	\N	2026-09-10 11:36:19	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
878	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 11:36:32	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
879	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 11:36:48	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
880	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 11:36:59	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
881	تحضير الرياضيات	تحضير الرياضيات	0	t	\N	\N	2026-09-10 11:37:27	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
882	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 11:39:14	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
883	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 11:39:28	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	unknown	f	\N	\N
884			26	f	\N	\N	2026-09-10 11:48:55	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	unknown	f	\N	\N
885			26	f	\N	\N	2026-09-10 11:53:11	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	unknown	f	\N	\N
886	تحضير	تحضير	5	f	\N	\N	2026-09-10 11:53:14	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	unknown	f	\N	\N
887	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 12:00:56	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	118
888	اوراق عمل	اوراق عمل	4	f	\N	\N	2026-09-10 12:00:56	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	27
889	توزيع المهارات	توزيع المهارات	1	f	\N	\N	2026-09-10 12:00:56	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	41
890	ملزمة	ملزمه	0	t	\N	\N	2026-09-10 12:00:57	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	37
891	حلول	حلول	2	f	\N	\N	2026-09-10 12:00:57	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	20
892	علوم سادس	علوم سادس	0	t	\N	\N	2026-09-10 12:00:57	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	f	\N	16
893	تحضير الرياضيات	تحضير الرياضيات	1	f	\N	\N	2026-09-10 12:00:58	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	t	unhealthy	193
894	ملزمة	ملزمه	0	t	\N	\N	2026-09-10 12:00:58	a495e0e5b9b3f5ee134c029db1c1f192c4fac23baea2072b44eb769235622124	pg	t	unhealthy	36
895			26	f	\N	\N	2026-09-10 12:04:46	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	232
896			26	f	\N	\N	2026-09-10 12:04:46	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	178
20915			26	f	\N	\N	2026-09-10 14:51:52	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	22
20916			26	f	\N	\N	2026-09-10 15:27:39	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	30
20917			26	f	\N	\N	2026-09-10 15:29:19	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	78
20918			26	f	\N	\N	2026-09-10 15:31:15	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	354
20919			26	f	\N	\N	2026-09-10 15:41:34	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	41
20920			26	f	\N	\N	2026-09-10 15:45:34	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	43
20921			26	f	\N	\N	2026-09-10 15:46:56	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	18
20922			26	f	\N	\N	2026-09-10 15:48:17	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	58
20923			26	f	\N	\N	2026-09-10 15:49:55	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	43
20924			26	f	\N	\N	2026-09-10 15:56:36	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	31
20925			26	f	\N	\N	2026-09-10 15:58:12	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	37
20926			26	f	\N	\N	2026-09-10 16:03:02	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	45
20927			26	f	\N	\N	2026-09-10 16:07:02	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	21
20928			26	f	\N	\N	2026-09-10 16:12:46	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	33
20929			26	f	\N	\N	2026-09-10 16:14:07	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	20
20930			26	f	\N	\N	2026-09-10 16:22:41	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	40
20931			26	f	\N	\N	2026-09-10 16:24:29	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	17
20932			26	f	\N	\N	2026-09-10 16:26:29	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	21
20933			26	f	\N	\N	2026-09-10 16:39:08	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	31
20934			26	f	\N	\N	2026-09-10 16:40:09	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	32
20935			26	f	\N	\N	2026-09-10 16:42:42	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	20
20936			26	f	\N	\N	2026-09-10 16:46:14	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	22
20937			26	f	\N	\N	2026-09-10 17:04:24	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	47
20938			26	f	\N	\N	2026-09-10 17:08:06	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	31
20939			26	f	\N	\N	2026-09-10 17:12:11	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	20
20940			26	f	\N	\N	2026-09-10 17:15:00	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	24
20941			26	f	\N	\N	2026-09-10 17:16:24	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	17
20942			26	f	\N	\N	2026-09-10 17:19:46	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	32
20943			26	f	\N	\N	2026-09-10 17:22:24	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	32
20944			26	f	\N	\N	2026-09-10 17:28:17	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	32
20945			26	f	\N	\N	2026-09-10 17:41:43	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	81
20946			26	f	\N	\N	2026-09-10 17:44:21	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	48
20947			26	f	\N	\N	2026-09-10 17:48:21	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	37
20948			26	f	\N	\N	2026-09-10 17:51:20	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	19
20949			26	f	\N	\N	2026-09-10 17:52:45	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	26
20950			26	f	\N	\N	2026-09-10 17:59:40	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	26
20951			26	f	\N	\N	2026-09-10 19:19:35	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	24
20952			26	f	\N	\N	2026-09-10 19:20:42	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	33
20953			26	f	\N	\N	2026-09-10 19:24:16	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	23
20954			26	f	\N	\N	2026-09-10 19:26:48	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	15
20955			26	f	\N	\N	2026-09-10 19:29:21	b2a3fe3a1f7701b0b29ccb058268aac20a033fb26021064b75b1197344c647a1	pg	f	\N	33
20956			26	f	\N	\N	2026-09-10 19:49:34	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	37
20957			26	f	\N	\N	2026-09-10 19:49:34	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	34
20958			26	f	\N	\N	2026-09-10 19:57:13	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	37
20959			26	f	\N	\N	2026-09-10 19:57:13	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	32
20960			26	f	\N	\N	2026-09-10 20:36:38	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	33
20961			26	f	\N	\N	2026-09-10 20:36:38	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	20
20962			26	f	\N	\N	2026-09-10 20:39:02	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	130
20963			26	f	\N	\N	2026-09-10 20:39:02	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	42
20964	رياضيات	رياضيات	3	f	\N	\N	2026-09-10 20:39:42	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	38
20965			26	f	\N	\N	2026-09-10 20:47:36	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	58
20966			26	f	\N	\N	2026-09-10 20:47:36	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	52
20967			26	f	\N	\N	2026-09-10 21:35:21	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	116
20968			26	f	\N	\N	2026-09-10 21:35:21	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	49
20969			26	f	\N	\N	2026-09-10 21:37:52	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	31
20970			26	f	\N	\N	2026-09-10 22:26:02	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	57
20971			26	f	\N	\N	2026-09-10 22:26:02	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	16
20972			26	f	\N	\N	2026-09-10 22:29:00	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	35
20973			26	f	\N	\N	2026-09-10 22:29:00	581fafd3b18a6b6f403fd42ce575b539f2275c0d9a1dbf2e46cbbdc4a2ec4911	pg	f	\N	32
20974			26	f	\N	\N	2026-09-12 11:16:52	\N	unknown	f	\N	\N
20975			26	f	\N	\N	2026-09-12 11:19:39	\N	unknown	f	\N	\N
20976			4	f	\N	\N	2026-09-12 11:19:40	\N	unknown	f	\N	\N
20977			26	f	\N	\N	2026-09-13 05:13:42	\N	unknown	f	\N	\N
20978			10	f	\N	\N	2026-09-13 05:13:45	\N	unknown	f	\N	\N
20979			26	f	\N	\N	2026-09-13 05:14:21	\N	unknown	f	\N	\N
\.


--
-- Data for Name: search_synonyms; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.search_synonyms (id, term, synonym, direction, scope, state, source, evidence_count, created_at, updated_at) FROM stdin;
1	تحضير	تحاضير	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	تحضير	اعداد	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
3	تحضير	خطه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
4	تحاضير	تحضير	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
5	ابتدايي	الابتداييه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
6	ابتدايي	ابتداييه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
7	متوسط	المتوسطه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
8	متوسط	متوسطه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
9	ثانوي	الثانويه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
10	ثانوي	ثانويه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
11	رياضيات	رياضه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
12	رياضيات	حساب	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
13	علوم	ساينس	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
14	انجليزي	انجليزيه	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
15	انجليزي	english	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
16	عرض	بوربوينت	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
17	عرض	presentation	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
18	عرض	powerpoint	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
19	اختبار	اختبارات	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
20	اختبار	امتحان	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
21	اختبار	امتحانات	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
22	ورقه	اوراق	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
23	ورقه	ورق	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
24	توزيع	توزيعات	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
25	منهج	مناهج	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
26	منهج	مقرر	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
27	فصل	الفصل	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
28	فصل	ترم	one_way	\N	active	seed	0	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: semesters; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.semesters (id, slug, name_ar, "position", created_at, updated_at) FROM stdin;
1	الفصل-الأول	الفصل الدراسي الأول	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	الفصل-الثاني	الفصل الدراسي الثاني	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	الفصل-الثالث	الفصل الدراسي الثالث	2	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.settings (id, key, value, created_at, updated_at) FROM stdin;
1	home.hero	{"eyebrow":"\\u0645\\u062a\\u062c\\u0631 \\u00ab\\u0627\\u0644\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0627\\u0644\\u062d\\u062f\\u064a\\u062b\\u0629\\u00bb","title":"\\u0643\\u0644 \\u0645\\u0627 \\u064a\\u062d\\u062a\\u0627\\u062c\\u0647 \\u0627\\u0644\\u0645\\u0639\\u0644\\u0651\\u0645 \\u0644\\u062a\\u062d\\u0636\\u064a\\u0631 \\u062d\\u0635\\u062a\\u0647","title_accent":"\\u062c\\u0627\\u0647\\u0632\\u064b\\u0627 \\u0642\\u0628\\u0644 \\u0628\\u062f\\u0627\\u064a\\u0629 \\u0627\\u0644\\u062f\\u0631\\u0633","subtitle":"\\u062a\\u062d\\u0627\\u0636\\u064a\\u0631 \\u0648\\u0639\\u0631\\u0648\\u0636 \\u0648\\u0623\\u0648\\u0631\\u0627\\u0642 \\u0639\\u0645\\u0644 \\u0648\\u0627\\u062e\\u062a\\u0628\\u0627\\u0631\\u0627\\u062a \\u0645\\u0628\\u0646\\u064a\\u0629 \\u0639\\u0644\\u0649 \\u0645\\u0646\\u0627\\u0647\\u062c \\u0648\\u0632\\u0627\\u0631\\u0629 \\u0627\\u0644\\u062a\\u0639\\u0644\\u064a\\u0645\\u060c \\u062a\\u0635\\u0644\\u0643 \\u0628\\u0635\\u064a\\u063a\\u0629 \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644 \\u0641\\u0648\\u0631 \\u0625\\u062a\\u0645\\u0627\\u0645 \\u0627\\u0644\\u062f\\u0641\\u0639.","cta_label":"\\u062a\\u0635\\u0641\\u0651\\u062d \\u0627\\u0644\\u0645\\u0646\\u062a\\u062c\\u0627\\u062a","cta_url":"\\/shop","cta_secondary_label":"\\u0643\\u064a\\u0641 \\u064a\\u0639\\u0645\\u0644 \\u0627\\u0644\\u0645\\u062a\\u062c\\u0631","cta_secondary_url":"\\/how-it-works","trust":["\\u062a\\u062d\\u0645\\u064a\\u0644 \\u0641\\u0648\\u0631\\u064a \\u0628\\u0639\\u062f \\u0627\\u0644\\u062f\\u0641\\u0639","\\u0645\\u0644\\u0641\\u0627\\u062a \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644","\\u062f\\u0639\\u0645 \\u0639\\u0628\\u0631 \\u0648\\u0627\\u062a\\u0633\\u0627\\u0628"]}	2026-09-04 06:01:26	2026-09-04 06:01:26
2	home.why	[{"title":"\\u0645\\u0628\\u0646\\u064a\\u0651\\u0629 \\u0639\\u0644\\u0649 \\u0627\\u0644\\u0645\\u0646\\u0647\\u062c","description":"\\u0643\\u0644 \\u0645\\u0644\\u0641 \\u0645\\u0631\\u062a\\u0628\\u0637 \\u0628\\u0645\\u0631\\u062d\\u0644\\u0629 \\u0648\\u0645\\u0627\\u062f\\u0629 \\u0648\\u0641\\u0635\\u0644 \\u062f\\u0631\\u0627\\u0633\\u064a \\u0645\\u062d\\u062f\\u0651\\u062f\\u060c \\u0641\\u0644\\u0627 \\u062a\\u0628\\u062d\\u062b \\u0641\\u064a \\u0645\\u062d\\u062a\\u0648\\u0649 \\u0644\\u0627 \\u064a\\u062e\\u0635\\u0651 \\u0635\\u0641\\u0651\\u0643."},{"title":"\\u062a\\u0639\\u062f\\u064a\\u0644 \\u0628\\u0644\\u0627 \\u0642\\u064a\\u0648\\u062f","description":"\\u0645\\u0644\\u0641\\u0627\\u062a Word \\u0648PowerPoint \\u0645\\u0641\\u062a\\u0648\\u062d\\u0629\\u060c \\u062a\\u063a\\u064a\\u0651\\u0631 \\u0641\\u064a\\u0647\\u0627 \\u0627\\u0633\\u0645 \\u0627\\u0644\\u0645\\u062f\\u0631\\u0633\\u0629 \\u0648\\u0627\\u0644\\u0645\\u0639\\u0644\\u0651\\u0645 \\u0648\\u0627\\u0644\\u062a\\u0648\\u0627\\u0631\\u064a\\u062e \\u0643\\u0645\\u0627 \\u062a\\u0634\\u0627\\u0621."},{"title":"\\u062a\\u062d\\u0645\\u064a\\u0644 \\u0641\\u0648\\u0631\\u064a","description":"\\u064a\\u0635\\u0644\\u0643 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0645\\u0628\\u0627\\u0634\\u0631\\u0629 \\u0628\\u0639\\u062f \\u0627\\u0644\\u062f\\u0641\\u0639 \\u0648\\u064a\\u0628\\u0642\\u0649 \\u0645\\u062d\\u0641\\u0648\\u0638\\u064b\\u0627 \\u0641\\u064a \\u062d\\u0633\\u0627\\u0628\\u0643 \\u0644\\u0644\\u062a\\u062d\\u0645\\u064a\\u0644 \\u0645\\u0631\\u0629 \\u0623\\u062e\\u0631\\u0649."},{"title":"\\u062a\\u062d\\u062f\\u064a\\u062b \\u0645\\u0639 \\u0627\\u0644\\u0645\\u0646\\u0647\\u062c","description":"\\u0639\\u0646\\u062f \\u062a\\u062d\\u062f\\u064a\\u062b \\u0627\\u0644\\u0645\\u0642\\u0631\\u0631 \\u062a\\u064f\\u062d\\u062f\\u064e\\u0651\\u062b \\u0627\\u0644\\u0646\\u0633\\u062e\\u0629\\u060c \\u0648\\u062a\\u062c\\u062f \\u0627\\u0644\\u0625\\u0635\\u062f\\u0627\\u0631 \\u0627\\u0644\\u062c\\u062f\\u064a\\u062f \\u0641\\u064a \\u0645\\u0634\\u062a\\u0631\\u064a\\u0627\\u062a\\u0643."}]	2026-09-04 06:01:26	2026-09-04 06:01:26
4	home.faq	[{"question":"\\u0643\\u064a\\u0641 \\u0623\\u0633\\u062a\\u0644\\u0645 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0628\\u0639\\u062f \\u0627\\u0644\\u062f\\u0641\\u0639\\u061f","answer":"\\u064a\\u0638\\u0647\\u0631 \\u0631\\u0627\\u0628\\u0637 \\u0627\\u0644\\u062a\\u062d\\u0645\\u064a\\u0644 \\u0645\\u0628\\u0627\\u0634\\u0631\\u0629 \\u0641\\u064a \\u0635\\u0641\\u062d\\u0629 \\u0627\\u0644\\u0637\\u0644\\u0628 \\u0628\\u0639\\u062f \\u0625\\u062a\\u0645\\u0627\\u0645 \\u0627\\u0644\\u062f\\u0641\\u0639\\u060c \\u0648\\u064a\\u0635\\u0644\\u0643 \\u0639\\u0644\\u0649 \\u0628\\u0631\\u064a\\u062f\\u0643 \\u0623\\u064a\\u0636\\u064b\\u0627. \\u0627\\u0644\\u0645\\u0644\\u0641 \\u064a\\u0628\\u0642\\u0649 \\u0645\\u062a\\u0627\\u062d\\u064b\\u0627 \\u0641\\u064a \\u062d\\u0633\\u0627\\u0628\\u0643."},{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641\\u0627\\u062a \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0627\\u0644\\u0645\\u0644\\u0641\\u0627\\u062a \\u0628\\u0635\\u064a\\u063a\\u0629 Word \\u0648PowerPoint \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644 \\u0628\\u0627\\u0644\\u0643\\u0627\\u0645\\u0644. \\u0623\\u0645\\u0627 \\u0645\\u0644\\u0641\\u0627\\u062a PDF \\u0641\\u0647\\u064a \\u062c\\u0627\\u0647\\u0632\\u0629 \\u0644\\u0644\\u0637\\u0628\\u0627\\u0639\\u0629 \\u0643\\u0645\\u0627 \\u0647\\u064a\\u060c \\u0648\\u064a\\u0648\\u0636\\u0651\\u062d \\u0643\\u0644 \\u0645\\u0646\\u062a\\u062c \\u0635\\u064a\\u063a\\u062a\\u0647 \\u0642\\u0628\\u0644 \\u0627\\u0644\\u0634\\u0631\\u0627\\u0621."},{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u062d\\u062a\\u0648\\u0649 \\u0645\\u0648\\u0627\\u0641\\u0642 \\u0644\\u0645\\u0646\\u0627\\u0647\\u062c \\u0648\\u0632\\u0627\\u0631\\u0629 \\u0627\\u0644\\u062a\\u0639\\u0644\\u064a\\u0645\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u0643\\u0644 \\u0645\\u0644\\u0641 \\u0645\\u0631\\u062a\\u0628\\u0637 \\u0628\\u0645\\u0631\\u062d\\u0644\\u0629 \\u0648\\u0635\\u0641 \\u0648\\u0645\\u0627\\u062f\\u0629 \\u0648\\u0641\\u0635\\u0644 \\u062f\\u0631\\u0627\\u0633\\u064a \\u0645\\u062d\\u062f\\u0651\\u062f \\u0648\\u0641\\u0642 \\u0627\\u0644\\u062e\\u0637\\u0629 \\u0627\\u0644\\u0645\\u0639\\u062a\\u0645\\u062f\\u0629 \\u0644\\u0644\\u0639\\u0627\\u0645 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0627\\u0644\\u062d\\u0627\\u0644\\u064a."},{"question":"\\u0647\\u0644 \\u064a\\u0645\\u0643\\u0646\\u0646\\u064a \\u0627\\u0633\\u062a\\u0631\\u062c\\u0627\\u0639 \\u0627\\u0644\\u0645\\u0628\\u0644\\u063a\\u061f","answer":"\\u0627\\u0644\\u0645\\u0646\\u062a\\u062c\\u0627\\u062a \\u0631\\u0642\\u0645\\u064a\\u0629 \\u0648\\u062a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0641\\u0648\\u0631 \\u0627\\u0644\\u062f\\u0641\\u0639\\u060c \\u0648\\u0644\\u0630\\u0644\\u0643 \\u064a\\u062e\\u0636\\u0639 \\u0627\\u0644\\u0627\\u0633\\u062a\\u0631\\u062c\\u0627\\u0639 \\u0644\\u0633\\u064a\\u0627\\u0633\\u0629 \\u0627\\u0644\\u0627\\u0633\\u062a\\u0631\\u062c\\u0627\\u0639 \\u0627\\u0644\\u0645\\u0646\\u0634\\u0648\\u0631\\u0629 \\u0641\\u064a \\u0627\\u0644\\u0645\\u062a\\u062c\\u0631. \\u062a\\u0648\\u0627\\u0635\\u0644 \\u0645\\u0639\\u0646\\u0627 \\u0625\\u0646 \\u0644\\u0645 \\u064a\\u0643\\u0646 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0645\\u0637\\u0627\\u0628\\u0642\\u064b\\u0627 \\u0644\\u0648\\u0635\\u0641\\u0647."},{"question":"\\u0647\\u0644 \\u0623\\u062d\\u062a\\u0627\\u062c \\u0625\\u0644\\u0649 \\u062d\\u0633\\u0627\\u0628 \\u0644\\u0644\\u0634\\u0631\\u0627\\u0621\\u061f","answer":"\\u064a\\u0645\\u0643\\u0646\\u0643 \\u0627\\u0644\\u0634\\u0631\\u0627\\u0621 \\u0643\\u0632\\u0627\\u0626\\u0631 \\u0628\\u0627\\u0633\\u062a\\u062e\\u062f\\u0627\\u0645 \\u0628\\u0631\\u064a\\u062f\\u0643 \\u0627\\u0644\\u0625\\u0644\\u0643\\u062a\\u0631\\u0648\\u0646\\u064a\\u060c \\u0648\\u0633\\u0646\\u0631\\u0633\\u0644 \\u0644\\u0643 \\u0631\\u0627\\u0628\\u0637 \\u0627\\u0644\\u0637\\u0644\\u0628. \\u0625\\u0646\\u0634\\u0627\\u0621 \\u062d\\u0633\\u0627\\u0628 \\u064a\\u062c\\u0639\\u0644 \\u062c\\u0645\\u064a\\u0639 \\u0645\\u0634\\u062a\\u0631\\u064a\\u0627\\u062a\\u0643 \\u0641\\u064a \\u0645\\u0643\\u0627\\u0646 \\u0648\\u0627\\u062d\\u062f."}]	2026-09-04 06:01:26	2026-09-04 06:01:26
5	home.cta	{"title":"\\u0627\\u0628\\u062f\\u0623 \\u0641\\u0635\\u0644\\u0643 \\u0627\\u0644\\u062f\\u0631\\u0627\\u0633\\u064a \\u0628\\u0645\\u0644\\u0641\\u0627\\u062a \\u062c\\u0627\\u0647\\u0632\\u0629","subtitle":"\\u0627\\u062e\\u062a\\u0631 \\u0645\\u0631\\u062d\\u0644\\u062a\\u0643 \\u0648\\u0645\\u0627\\u062f\\u062a\\u0643\\u060c \\u0648\\u062d\\u0645\\u0651\\u0644 \\u0645\\u0627 \\u062a\\u062d\\u062a\\u0627\\u062c\\u0647 \\u062e\\u0644\\u0627\\u0644 \\u062f\\u0642\\u0627\\u0626\\u0642.","cta_label":"\\u062a\\u0635\\u0641\\u0651\\u062d \\u0627\\u0644\\u0645\\u0646\\u062a\\u062c\\u0627\\u062a","cta_url":"\\/shop"}	2026-09-04 06:01:26	2026-09-04 06:01:26
6	home.promo_coupon	{"code":"PREVIEW15"}	2026-09-04 06:01:26	2026-09-04 06:01:26
3	home.benefits	[{"title":"\\u062f\\u0641\\u0639 \\u0622\\u0645\\u0646","description":"\\u0645\\u062f\\u0649 \\u0648\\u0627\\u0644\\u0628\\u0637\\u0627\\u0642\\u0627\\u062a \\u0648Apple Pay \\u0639\\u0628\\u0631 \\u0628\\u0648\\u0627\\u0628\\u0629 \\u062f\\u0641\\u0639 \\u0645\\u0648\\u062b\\u0651\\u0642\\u0629\\u060c \\u062f\\u0648\\u0646 \\u062d\\u0641\\u0638 \\u0628\\u064a\\u0627\\u0646\\u0627\\u062a \\u0628\\u0637\\u0627\\u0642\\u062a\\u0643 \\u0644\\u062f\\u064a\\u0646\\u0627."},{"title":"\\u0641\\u0627\\u062a\\u0648\\u0631\\u0629 \\u0644\\u0643\\u0644 \\u0637\\u0644\\u0628","description":"\\u0641\\u0627\\u062a\\u0648\\u0631\\u0629 \\u0625\\u0644\\u0643\\u062a\\u0631\\u0648\\u0646\\u064a\\u0629 \\u0628\\u0627\\u0633\\u0645\\u0643 \\u0623\\u0648 \\u0628\\u0627\\u0633\\u0645 \\u0645\\u0646\\u0634\\u0623\\u062a\\u0643 \\u0644\\u0643\\u0644 \\u0637\\u0644\\u0628\\u060c \\u062c\\u0627\\u0647\\u0632\\u0629 \\u0644\\u0644\\u0637\\u0628\\u0627\\u0639\\u0629 \\u0645\\u0646 \\u0635\\u0641\\u062d\\u0629 \\u0627\\u0644\\u0637\\u0644\\u0628 \\u2014 \\u0627\\u0644\\u0623\\u0633\\u0639\\u0627\\u0631 \\u0646\\u0647\\u0627\\u0626\\u064a\\u0629 \\u0628\\u0644\\u0627 \\u0636\\u0631\\u064a\\u0628\\u0629 \\u0645\\u0636\\u0627\\u0641\\u0629."},{"title":"\\u062f\\u0639\\u0645 \\u0642\\u0628\\u0644 \\u0627\\u0644\\u0634\\u0631\\u0627\\u0621 \\u0648\\u0628\\u0639\\u062f\\u0647","description":"\\u0627\\u0633\\u0623\\u0644 \\u0639\\u0646 \\u0645\\u062d\\u062a\\u0648\\u0649 \\u0623\\u064a \\u0645\\u0644\\u0641 \\u0642\\u0628\\u0644 \\u0634\\u0631\\u0627\\u0626\\u0647\\u060c \\u0648\\u062a\\u0648\\u0627\\u0635\\u0644 \\u0645\\u0639\\u0646\\u0627 \\u0625\\u0646 \\u0648\\u0627\\u062c\\u0647\\u062a \\u0623\\u064a \\u0645\\u0634\\u0643\\u0644\\u0629 \\u0641\\u064a \\u0627\\u0644\\u062a\\u062d\\u0645\\u064a\\u0644."}]	2026-09-04 06:01:26	2026-09-09 04:25:35
\.


--
-- Data for Name: sitemap_url_states; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.sitemap_url_states (id, provider, loc, content_hash, lastmod_at, created_at, updated_at) FROM stdin;
3	products	/products/dev-commerce-001	89f049522d7e64f549f3561e6f5736544ec695a11564ed9e67701a05ad132c2f	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
4	products	/products/dev-commerce-002	620866fa7da9020c8bc63bdedf33f7be731b88eb4133bbcc4d78c88c55a09a18	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
6	products	/products/تحضير-الرياضيات-سادس-ابتدائي-الفصل-الأول	56de54fd50073948e8b7bb85bfdb85e6712cd96b4debf9eb2353e4dd986ad52a	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
8	products	/products/عرض-بوربوينت-العلوم-رابع-ابتدائي-الفصل-الثاني	8ab022b57a9ba0c151c446db3d93ae0558049b4034eb4a0fcaca35cd06563dc9	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
9	products	/products/أوراق-عمل-لغتي-ثالث-ابتدائي-الفصل-الأول	095b23ef9a904cea839f1cf73e235b45b1429ec8636c652a7b369b022999a9ca	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
10	products	/products/اختبارات-الدراسات-الإسلامية-خامس-ابتدائي-الفصل-الثالث	6387dd0b1744e9cd38bfae8ec7e076fccde831e38148474da812f8723176b8b2	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
12	products	/products/توزيع-المهارات-الرقمية-أول-متوسط-الفصل-الأول	0678cbbc6b01e30e34164ddabe4804b7dab8edcd5ec52441d470b33e62ea0479	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
13	products	/products/تحضير-اللغة-الإنجليزية-ثاني-متوسط-الفصل-الثاني	957b4447035e46dd079b15ae3b6ee543065763472e52ee170de722113c30b7a1	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
14	products	/products/عرض-بوربوينت-الفيزياء-أول-ثانوي-الفصل-الأول	bcf1dfe2ce3ed8d7a4a9ca319d281f7838ef750cc6f5a8377cd6b4ad9edd79d7	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
15	products	/products/أوراق-عمل-الكيمياء-ثاني-ثانوي-الفصل-الثاني	55cea9a24c43f3881e0f0511d306c60c60fa69bfe845f2f32a559d1f758ebeef	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
16	products	/products/وسائل-تعليمية-القرآن-الكريم-ثاني-ابتدائي-الفصل-الأول	fe25f90a1c176802d7f7cbfed8645a7a1b5525190240203ed126235ac82e05be	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
17	products	/products/حلول-الرياضيات-ثالث-متوسط-الفصل-الثالث	0027608a95b2325a6e7f38aae6606b48a5f01906922ab2d3f494517eab95cec6	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
18	products	/products/تحضير-الدراسات-الاجتماعية-ثالث-ثانوي-الفصل-الثاني	278da106d54d001839f13628445134ef482f159c99a0712c04a46f4124ff626b	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
20	products	/products/عرض-بوربوينت-اللغة-العربية-أول-متوسط-الفصل-الثاني	57e18f07d4625addea1095de5f8036e80d82d804cf4e06ce58619464776113d0	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
21	products	/products/أوراق-عمل-الرياضيات-أول-ابتدائي-الفصل-الأول	afa1e116d884af44ad80dfd3b03f6f2c425a4abf5634ad4131100f6e0b6bd8cf	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
22	products	/products/تحضير-العلوم-خامس-ابتدائي-الفصل-الثالث	90b6519e0eccaf73282b70c2ae356357984c4c13dd1489af89328b8e3ffeeda0	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
23	products	/products/اختبارات-اللغة-الإنجليزية-ثالث-متوسط-الفصل-الأول	5471bd2f904d4ef2608ae3c82b3bb8ff3232d08393ae070fbd431a5c2a7505df	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
24	products	/products/وسائل-تعليمية-التربية-الفنية-ثالث-ابتدائي-الفصل-الثاني	b88dee0f358c6aec64d2fead5596d7bd60211c0073b47dda42d3823f6b1e15f8	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
25	products	/products/توزيع-الكفايات-اللغوية-ثاني-ثانوي-الفصل-الأول	53a375c9ee66c601235ee4c632a225ef49e24ad8e6473d9a8d6d520fa4cc8750	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
26	products	/products/حلول-الفيزياء-ثالث-ثانوي-الفصل-الثالث	f2ef54476b82208209c82076d50a1c7bfe1e1c1faf791695d383347f99aee8ec	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
27	products	/products/عرض-بوربوينت-الدراسات-الإسلامية-سادس-ابتدائي-الفصل-الأول	b5d936aa22a02759c0ef5aad4ab14f6b62e2e009ba0dad0385255b021d3d16c6	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
28	products	/products/أوراق-عمل-المهارات-الحياتية-ثاني-متوسط-الفصل-الثالث	d502ca4e0e4713c7ee8b2e9aebcf53b12c73bec1ba855885e3d9b2f6bec9be49	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
29	products	/products/تحضير-التفكير-الناقد-أول-ثانوي-الفصل-الثاني	d6f821d0c74c8ef1ee3b4589be5c018677ee52bb63f81d401dd645a14cba5372	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
30	products	/products/اختبارات-علم-البيئة-ثاني-ثانوي-الفصل-الثالث	666b91aa0dfcdfe74965e754d9f9902fb15cb9584aca0d2430bfaa2d95700898	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
31	products	/products/وسائل-تعليمية-التربية-البدنية-أول-ابتدائي-الفصل-الثاني	31465f665a9b7cdd68e1488485587a4624e120d8eab58bcd3abf26f866f74535	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
19	products	/products/اختبارات-الأحياء-ثالث-ثانوي-الفصل-الأول	ae4c7409fab87c438d49b4b37396a1578af33120cf9547990d5ccf78b0e1212f	2026-09-13 05:26:19	2026-09-04 09:27:53	2026-09-13 05:26:19
1	grades	/grades/ثاني-ثانوي	217807ae09062990dcaedd16ff0a5514a702e0e3b6c4afa3bac691da7052a6c5	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
5	grades	/grades/ثالث-ثانوي	1e570b404938a8d2ef5cd759dd54bf71eb4011fbce27f7f68c6a5915869821fb	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
2	stages	/stages/ابتدائي	750e3dc9487841d180e180f16e4279724e813bbdbda5a42c0ad9328897812933	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
7	stages	/stages/متوسط	4ccab36b4a300b555b585249a25f2e85bf4ce3933724ef1f4a7f5a7d9003edae	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
11	stages	/stages/ثانوي	ffe8ef62659ce52f6182e6312b33951f7cc23d3ffe2eb0268d664d451b6a20d7	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
32	subjects	/subjects/الرياضيات	983865bee8478efeafb28228ddb8773bc6eb6d0972db72d2b9ca210ff10c1bb5	2026-09-04 09:27:53	2026-09-04 09:27:53	2026-09-04 09:27:53
33	kinds	/kinds/تحضير	3eec30a516186f8619c6fe80d71df974edbec4665284feb7d4c6845243ede153	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
34	kinds	/kinds/عرض-بوربوينت	6a429525a14c85327ae569397f0bd46040dd93a3c413e2bdf7e543960455a230	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
35	kinds	/kinds/أوراق-عمل	404b63e68c2f4e358d361536407de1499acbee765ede23ea9f6210f9714eb57f	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
36	kinds	/kinds/اختبارات	2c7554c040b258bacf6f5507a3c9425ee6404cfdea4b911530a9403038458fcb	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
37	kinds	/kinds/وسائل-تعليمية	bb5025a106a5bb502c2fd0f00807b10fba78309cf47a888fd5f4c735d77d7d42	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
38	content	/	69c9632acfcc243732279d11a57df843477311cf160cd7098ce8f3f7e99bbf60	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
39	content	/stages	8a9f33af5199d4fc6aab7a98ed324767406a1840c6a8765c95e438f48e4febc3	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
40	content	/grades	683397f3add607e5f1ded5064a32ab631dbd0c34d6412ecce0bfc8b005b661e2	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
41	content	/subjects	dcf710cf1a8d76a6946b82447ba3d9c66c4aae08b02be08022cc895675a236a1	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
42	content	/semesters	a97c3304a38e7ca82fed6ed410e0e0d671c4f5c926a48dbced741c5fa72c13ac	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
43	content	/kinds	317328e00290e611d83e77a0486063d03c4eb2e2c377775950ded148dbb2e868	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
44	semesters	/semesters/الفصل-الأول	3e03fcde726514a000422991593bc95824069e6d84137036a15ffbc33d9f0dae	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
45	semesters	/semesters/الفصل-الثاني	b7a81de2f640084df481f48f085547de845eda3ffb4534471c28444d8227793b	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
46	semesters	/semesters/الفصل-الثالث	81768b4fbe8a772eb4654439081d79490651acc001d10174fda515030609fd23	2026-09-04 09:27:54	2026-09-04 09:27:54	2026-09-04 09:27:54
\.


--
-- Data for Name: stages; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.stages (id, slug, name_ar, "position", created_at, updated_at) FROM stdin;
1	ابتدائي	ابتدائي	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	متوسط	متوسط	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	ثانوي	ثانوي	2	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: studio_entry_prefs; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_entry_prefs (id, admin_user_id, registry_id, favorite, last_opened_at, created_at, updated_at) FROM stdin;
7	1	tah/trust-strip.0803	f	2026-09-04 16:56:17	2026-09-04 16:56:17	2026-09-04 16:56:17
3	1	tah/trust-strip.0802	f	2026-09-04 16:56:18	2026-09-04 16:56:10	2026-09-04 16:56:18
8	1	tah/hover-gallery.0902	f	2026-09-04 16:56:23	2026-09-04 16:56:23	2026-09-04 16:56:23
9	1	tah/story.1501	f	2026-09-04 16:56:26	2026-09-04 16:56:26	2026-09-04 16:56:26
10	1	tah/section-heading.1001	f	2026-09-04 17:08:19	2026-09-04 17:08:19	2026-09-04 17:08:19
14	1	tah/hero.0702	f	2026-09-04 20:28:27	2026-09-04 20:28:27	2026-09-04 20:28:27
15	1	tah/purchase-bar.0704	f	2026-09-04 20:28:30	2026-09-04 20:28:27	2026-09-04 20:28:30
17	1	tah/hero.0701	f	2026-09-04 20:28:31	2026-09-04 20:28:31	2026-09-04 20:28:31
5	1	tah/reviews.0807	f	2026-09-04 20:28:33	2026-09-04 16:56:15	2026-09-04 20:28:33
18	1	tah/collection.0104	f	2026-09-04 21:32:26	2026-09-04 21:32:26	2026-09-04 21:32:26
11	1	tah/collection.0105	f	2026-09-04 21:32:27	2026-09-04 20:27:49	2026-09-04 21:32:27
19	1	tah/collection.0106	f	2026-09-04 21:32:28	2026-09-04 21:32:28	2026-09-04 21:32:28
12	1	tah/collection.0101	f	2026-09-04 23:10:42	2026-09-04 20:27:59	2026-09-04 23:10:42
20	1	tah/card.0109	f	2026-09-09 03:47:23	2026-09-04 21:32:29	2026-09-09 03:47:23
21	1	tah/card.0108	f	2026-09-09 03:47:26	2026-09-04 21:32:30	2026-09-09 03:47:26
22	1	tah/accordion.1909	f	2026-09-10 14:39:57	2026-09-10 14:39:57	2026-09-10 14:39:57
13	1	tah/collection.0102	f	2026-09-10 14:45:29	2026-09-04 20:28:00	2026-09-10 14:45:29
16	1	tah/trust-strip.0801	f	2026-09-10 15:58:50	2026-09-04 20:28:28	2026-09-10 15:58:50
1	1	tah/collection.0103	t	2026-09-10 15:58:53	2026-09-04 16:37:55	2026-09-10 15:58:53
2	1	tah/collection.0107	f	2026-09-10 15:58:56	2026-09-04 16:54:20	2026-09-10 15:58:56
\.


--
-- Data for Name: studio_media; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_media (id, name, url, alt_ar, width, height, mime, bytes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: studio_page_versions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_page_versions (id, page_id, version, kind, label, document, schema_version, created_by, created_at) FROM stdin;
9	1	8	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"e7da5952-8b4e-40ed-99b0-07ddf8efca09","registryId":"tah\\/trust-strip.0801","props":[]}]}	3	1	2026-09-04 14:57:35
10	1	9	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 14:58:10
11	1	10	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}}]}	3	1	2026-09-04 16:45:53
12	1	11	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[]}}]}	3	1	2026-09-04 17:02:53
13	1	12	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":null,"answer":null}]}}]}	3	1	2026-09-04 17:03:08
14	1	13	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."}]}}]}	3	1	2026-09-04 17:03:25
15	1	14	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":null,"answer":null}]}}]}	3	1	2026-09-04 17:03:30
16	1	15	publish	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}}]}	3	1	2026-09-04 17:03:37
17	1	16	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}}]}	3	1	2026-09-04 17:08:35
18	1	17	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":""}}]}	3	1	2026-09-04 17:58:44
19	1	18	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":""}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 17:59:04
20	1	19	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":""},"query":{"source":"manual","limit":1,"ids":[]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 18:00:52
21	1	20	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":""},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 18:01:36
22	1	21	publish	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 18:02:01
23	1	22	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[]}]}	3	1	2026-09-04 18:10:24
24	1	23	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[]}}]}	3	1	2026-09-04 18:12:06
25	1	24	publish	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}}]}	3	1	2026-09-04 18:12:06
26	1	25	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 18:51:41
27	1	26	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 20:28:14
28	1	27	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 23:11:22
29	1	28	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"52868390-c891-441c-8fe5-c11905ddd533","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 23:11:45
30	1	29	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 23:12:59
31	1	30	autosave	\N	{"schema":3,"nodes":[{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 23:24:18
32	1	31	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 23:24:19
36	1	32	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"},"hidden":{"mob":true}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-10 14:25:07
37	1	33	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8},"hidden":{"desk":true}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"},"hidden":{"mob":true}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-10 15:56:44
38	1	34	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8},"hidden":{"desk":true}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"},"hidden":{"mob":true}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"1d5d6b75-ce28-4714-b338-f392b8ce7193","registryId":"tah\\/collection.0102","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}}]}	3	1	2026-09-10 17:53:04
39	1	35	autosave	\N	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8},"hidden":{"desk":true}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"},"hidden":{"mob":true}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"1d5d6b75-ce28-4714-b338-f392b8ce7193","registryId":"tah\\/collection.0102","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"20d44fe5-b020-443c-a59f-b23c9ee91444","registryId":"tah\\/card.0108","props":{"ctaLabel":""}}]}	3	1	2026-09-10 17:53:21
\.


--
-- Data for Name: studio_pages; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_pages (id, page_key, locale, name, draft_document, published_document, schema_version, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
1	g	ar	gg	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8},"hidden":{"desk":true}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"},"hidden":{"mob":true}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}},{"id":"e886b13b-d707-4070-ba13-b925b626367b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"172d9b9c-e01d-4fd9-9d88-2bcbd2e245f0","registryId":"tah\\/collection.0107","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"41506a05-6679-4ff1-bff0-ac0dfb1a674b","registryId":"tah\\/collection.0101","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"1d5d6b75-ce28-4714-b338-f392b8ce7193","registryId":"tah\\/collection.0102","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"20d44fe5-b020-443c-a59f-b23c9ee91444","registryId":"tah\\/card.0108","props":{"ctaLabel":""}}]}	{"schema":3,"nodes":[{"id":"cfcc1cbe-408f-4af4-834f-c807ac1cf5eb","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}},{"id":"ae96469f-7c1e-47ec-946b-8ebd5bfbd74e","registryId":"tah\\/collection.0101","props":{"heading":"\\u0642\\u0633\\u0645\\u064c \\u0623\\u064f\\u0636\\u064a\\u0641 \\u0645\\u0646 \\u0645\\u062d\\u0631\\u0651\\u0631 \\u0627\\u0644\\u0639\\u0646\\u0635\\u0631","headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"best","limit":6}},{"id":"5c9ea27c-af85-4878-90fb-df894564ce60","registryId":"tah\\/faq.1201","props":{"title":"\\u0627\\u0644\\u0623\\u0633\\u0626\\u0644\\u0629 \\u0627\\u0644\\u0634\\u0627\\u0626\\u0639\\u0629","items":[{"question":"\\u0647\\u0644 \\u0627\\u0644\\u0645\\u0644\\u0641 \\u0642\\u0627\\u0628\\u0644 \\u0644\\u0644\\u062a\\u0639\\u062f\\u064a\\u0644\\u061f","answer":"\\u0646\\u0639\\u0645\\u060c \\u064a\\u064f\\u0633\\u0644\\u064e\\u0651\\u0645 \\u0628\\u0635\\u064a\\u063a\\u0629 DOCX \\u0642\\u0627\\u0628\\u0644\\u0629 \\u0644\\u0644\\u062a\\u062d\\u0631\\u064a\\u0631."},{"question":"\\u0643\\u0645 \\u0639\\u062f\\u062f \\u0627\\u0644\\u0635\\u0641\\u062d\\u0627\\u062a\\u061f","answer":"\\u062b\\u0645\\u0627\\u0646 \\u0648\\u0623\\u0631\\u0628\\u0639\\u0648\\u0646 \\u0635\\u0641\\u062d\\u0629."}]}},{"id":"dbe51917-30d0-4357-ba8f-00b9722da624","registryId":"tah\\/section-heading.1001","props":{"title":"\\u0639\\u0646\\u0648\\u0627\\u0646 \\u0627\\u0644\\u0642\\u0633\\u0645","eyebrow":null,"description":null,"as":"h2","align":"start"}},{"id":"9fd3fbfb-42fe-4e27-ae92-c7afb644ffa2","registryId":"tah\\/card.0108","props":{"ctaLabel":"\\u0627\\u0634\\u062a\\u0631\\u0650 \\u0627\\u0644\\u0622\\u0646"},"query":{"source":"manual","limit":1,"ids":[2]}},{"id":"9dd68b02-667b-4a29-9874-9276e7121a4a","registryId":"tah\\/collection.0103","props":{"heading":"","headingLevel":"h2","emptyMsg":"","errorMsg":"","hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":"","cls":""},"query":{"source":"new","limit":8}},{"id":"098a1c71-75dd-4563-bf73-af8542dd1cff","registryId":"tah\\/facts.1802","props":[],"query":{"source":"manual","limit":1,"ids":[13]}}]}	1	35	24	1	2026-09-04 18:12:16	2026-09-04 07:10:15	2026-09-10 17:53:21
8	ff	ar	شش	{"schema":1,"nodes":[]}	\N	1	1	\N	1	\N	2026-09-10 22:35:41	2026-09-10 22:35:41
\.


--
-- Data for Name: studio_symbol_versions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_symbol_versions (id, symbol_id, version, kind, label, document, schema_version, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: studio_symbols; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_symbols (id, name, draft_document, published_document, schema_version, version, published_version, updated_by, published_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: studio_templates; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.studio_templates (id, name, document, schema_version, created_by, created_at, updated_at, source) FROM stdin;
1	كاروسيل الأكثر مبيعًا	{"schema":3,"nodes":[{"id":"b0eb79dc-ce00-47a2-9108-6f16d7330c1d","registryId":"tah\\/collection.0103","props":{"heading":null,"headingLevel":"h2","emptyMsg":null,"errorMsg":null,"hSize":11,"hWeight":800,"cols":3,"gap":10,"density":"comfy","mobCols":2,"radius":6,"border":"none","shadow":"none","surface":"plain","ratio":"square","fit":"cover","parts":{"img":true,"title":true,"price":true,"badge":false,"cta":false},"hover":"lift","spotlight":false,"motion":5,"entrance":"up","anchor":null,"cls":null},"query":{"source":"new","limit":8}}]}	3	1	2026-09-04 16:38:39	2026-09-04 16:38:39	node
\.


--
-- Data for Name: subjects; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.subjects (id, slug, name_ar, icon, "position", created_at, updated_at) FROM stdin;
1	لغتي	لغتي	\N	0	2026-09-04 06:01:24	2026-09-04 06:01:24
2	اللغة العربية	اللغة العربية	\N	1	2026-09-04 06:01:24	2026-09-04 06:01:24
3	الكفايات اللغوية	الكفايات اللغوية	\N	2	2026-09-04 06:01:24	2026-09-04 06:01:24
4	الرياضيات	الرياضيات	\N	3	2026-09-04 06:01:24	2026-09-04 06:01:24
5	العلوم	العلوم	\N	4	2026-09-04 06:01:24	2026-09-04 06:01:24
6	الفيزياء	الفيزياء	\N	5	2026-09-04 06:01:24	2026-09-04 06:01:24
7	الكيمياء	الكيمياء	\N	6	2026-09-04 06:01:24	2026-09-04 06:01:24
8	الأحياء	الأحياء	\N	7	2026-09-04 06:01:24	2026-09-04 06:01:24
9	الدراسات الإسلامية	الدراسات الإسلامية	\N	8	2026-09-04 06:01:24	2026-09-04 06:01:24
10	القرآن الكريم	القرآن الكريم	\N	9	2026-09-04 06:01:24	2026-09-04 06:01:24
11	الدراسات الاجتماعية	الدراسات الاجتماعية	\N	10	2026-09-04 06:01:24	2026-09-04 06:01:24
12	اللغة الإنجليزية	اللغة الإنجليزية	\N	11	2026-09-04 06:01:24	2026-09-04 06:01:24
13	المهارات الرقمية	المهارات الرقمية	\N	12	2026-09-04 06:01:24	2026-09-04 06:01:24
14	المهارات الحياتية	المهارات الحياتية	\N	13	2026-09-04 06:01:24	2026-09-04 06:01:24
15	التفكير الناقد	التفكير الناقد	\N	14	2026-09-04 06:01:24	2026-09-04 06:01:24
16	التربية الفنية	التربية الفنية	\N	15	2026-09-04 06:01:24	2026-09-04 06:01:24
17	التربية البدنية	التربية البدنية	\N	16	2026-09-04 06:01:24	2026-09-04 06:01:24
18	علم البيئة	علم البيئة	\N	17	2026-09-04 06:01:24	2026-09-04 06:01:24
\.


--
-- Data for Name: theme_versions; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.theme_versions (id, document, etag, kind, label, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: tahdheer
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) FROM stdin;
1	Hisham AlKaltham	Hishamalkaltham@gmail.com	\N	$2y$12$kJf/fw5DQsaiAiltzrI/peTok5jAwebm8ZSj3o5qcOMDzpAWzUrbe	\N	2026-09-12 12:49:46	2026-09-12 12:49:46
2	Hesham Gamer	heshamg4mer@gmail.com	\N	$2y$12$EcVmac4eCgB2I2Hh5bDM5ewrd8yW6RRow91ZQGGSGVtpFlONbggIK	\N	2026-09-12 12:49:46	2026-09-12 12:49:46
3	Arod Haditha	arodhaditha@gmail.com	\N	$2y$12$3E5oMGeEm4vZoapH.AvK/eHg7GLeB57nS8Zbm6Z.38HxmW6Rm7qi6	\N	2026-09-12 12:49:46	2026-09-12 12:49:46
\.


--
-- Name: admin_audit_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.admin_audit_log_id_seq', 39, true);


--
-- Name: admin_notes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.admin_notes_id_seq', 33, true);


--
-- Name: admin_users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.admin_users_id_seq', 6, true);


--
-- Name: ai_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.ai_requests_id_seq', 1, false);


--
-- Name: analytics_daily_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.analytics_daily_id_seq', 1, false);


--
-- Name: analytics_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.analytics_events_id_seq', 56, true);


--
-- Name: analytics_product_daily_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.analytics_product_daily_id_seq', 1, false);


--
-- Name: asset_extractions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.asset_extractions_id_seq', 32, true);


--
-- Name: assets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.assets_id_seq', 29, true);


--
-- Name: auth_identities_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.auth_identities_id_seq', 29, true);


--
-- Name: campaigns_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.campaigns_id_seq', 1, true);


--
-- Name: cart_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.cart_items_id_seq', 114, true);


--
-- Name: carts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.carts_id_seq', 114, true);


--
-- Name: coupons_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.coupons_id_seq', 3, true);


--
-- Name: crawl_runs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.crawl_runs_id_seq', 1, false);


--
-- Name: crawled_pages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.crawled_pages_id_seq', 1, false);


--
-- Name: curriculum_aliases_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.curriculum_aliases_id_seq', 261, true);


--
-- Name: customer_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.customer_sessions_id_seq', 30, true);


--
-- Name: customers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.customers_id_seq', 74, true);


--
-- Name: design_component_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.design_component_versions_id_seq', 1, false);


--
-- Name: design_components_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.design_components_id_seq', 6, true);


--
-- Name: design_heroes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.design_heroes_id_seq', 1, true);


--
-- Name: download_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.download_events_id_seq', 1, true);


--
-- Name: download_grants_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.download_grants_id_seq', 1, false);


--
-- Name: entitlements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.entitlements_id_seq', 27, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: features_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.features_id_seq', 1, false);


--
-- Name: grades_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.grades_id_seq', 12, true);


--
-- Name: internal_links_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.internal_links_id_seq', 1, false);


--
-- Name: invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.invoices_id_seq', 25, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: kinds_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.kinds_id_seq', 7, true);


--
-- Name: magic_links_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.magic_links_id_seq', 31, true);


--
-- Name: material_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.material_requests_id_seq', 3, true);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.migrations_id_seq', 53, true);


--
-- Name: navigation_menus_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.navigation_menus_id_seq', 3, true);


--
-- Name: navigation_revisions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.navigation_revisions_id_seq', 1, false);


--
-- Name: not_found_hits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.not_found_hits_id_seq', 88, true);


--
-- Name: order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.order_items_id_seq', 153, true);


--
-- Name: orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.orders_id_seq', 153, true);


--
-- Name: payment_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.payment_events_id_seq', 28, true);


--
-- Name: payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.payments_id_seq', 30, true);


--
-- Name: product_authors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_authors_id_seq', 1, false);


--
-- Name: product_fact_evidence_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_fact_evidence_id_seq', 6, true);


--
-- Name: product_facts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_facts_id_seq', 33, true);


--
-- Name: product_faqs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_faqs_id_seq', 96, true);


--
-- Name: product_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_files_id_seq', 29, true);


--
-- Name: product_knowledge_facts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_knowledge_facts_id_seq', 4, true);


--
-- Name: product_layouts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_layouts_id_seq', 1, false);


--
-- Name: product_media_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_media_id_seq', 24, true);


--
-- Name: product_prices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_prices_id_seq', 26, true);


--
-- Name: product_seo_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_seo_id_seq', 24, true);


--
-- Name: product_translations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.product_translations_id_seq', 1, false);


--
-- Name: products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.products_id_seq', 26, true);


--
-- Name: quote_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.quote_requests_id_seq', 38, true);


--
-- Name: redirect_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.redirect_rules_id_seq', 1, false);


--
-- Name: refunds_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.refunds_id_seq', 1, true);


--
-- Name: review_aggregates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.review_aggregates_id_seq', 18, true);


--
-- Name: review_category_scores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.review_category_scores_id_seq', 240, true);


--
-- Name: review_media_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.review_media_id_seq', 1, false);


--
-- Name: review_replies_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.review_replies_id_seq', 2, true);


--
-- Name: review_votes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.review_votes_id_seq', 14, true);


--
-- Name: reviews_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.reviews_id_seq', 105, true);


--
-- Name: search_index_state_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.search_index_state_id_seq', 1, true);


--
-- Name: search_queries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.search_queries_id_seq', 20979, true);


--
-- Name: search_synonyms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.search_synonyms_id_seq', 29, true);


--
-- Name: semesters_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.semesters_id_seq', 3, true);


--
-- Name: settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.settings_id_seq', 6, true);


--
-- Name: sitemap_url_states_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.sitemap_url_states_id_seq', 46, true);


--
-- Name: stages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.stages_id_seq', 3, true);


--
-- Name: studio_entry_prefs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_entry_prefs_id_seq', 22, true);


--
-- Name: studio_media_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_media_id_seq', 1, false);


--
-- Name: studio_page_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_page_versions_id_seq', 39, true);


--
-- Name: studio_pages_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_pages_id_seq', 8, true);


--
-- Name: studio_symbol_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_symbol_versions_id_seq', 1, false);


--
-- Name: studio_symbols_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_symbols_id_seq', 1, false);


--
-- Name: studio_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.studio_templates_id_seq', 1, true);


--
-- Name: subjects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.subjects_id_seq', 18, true);


--
-- Name: theme_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.theme_versions_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: tahdheer
--

SELECT pg_catalog.setval('public.users_id_seq', 3, true);


--
-- Name: admin_audit_log admin_audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_audit_log
    ADD CONSTRAINT admin_audit_log_pkey PRIMARY KEY (id);


--
-- Name: admin_notes admin_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_notes
    ADD CONSTRAINT admin_notes_pkey PRIMARY KEY (id);


--
-- Name: admin_users admin_users_email_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_email_unique UNIQUE (email);


--
-- Name: admin_users admin_users_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_pkey PRIMARY KEY (id);


--
-- Name: ai_requests ai_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.ai_requests
    ADD CONSTRAINT ai_requests_pkey PRIMARY KEY (id);


--
-- Name: analytics_daily analytics_daily_day_event_type_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_daily
    ADD CONSTRAINT analytics_daily_day_event_type_unique UNIQUE (day, event_type);


--
-- Name: analytics_daily analytics_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_daily
    ADD CONSTRAINT analytics_daily_pkey PRIMARY KEY (id);


--
-- Name: analytics_events analytics_events_dedup_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_events
    ADD CONSTRAINT analytics_events_dedup_key_unique UNIQUE (dedup_key);


--
-- Name: analytics_events analytics_events_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_events
    ADD CONSTRAINT analytics_events_pkey PRIMARY KEY (id);


--
-- Name: analytics_product_daily analytics_product_daily_day_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_product_daily
    ADD CONSTRAINT analytics_product_daily_day_product_id_unique UNIQUE (day, product_id);


--
-- Name: analytics_product_daily analytics_product_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_product_daily
    ADD CONSTRAINT analytics_product_daily_pkey PRIMARY KEY (id);


--
-- Name: asset_extractions asset_extractions_content_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.asset_extractions
    ADD CONSTRAINT asset_extractions_content_unique UNIQUE (asset_sha256, extractor, extractor_version);


--
-- Name: asset_extractions asset_extractions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.asset_extractions
    ADD CONSTRAINT asset_extractions_pkey PRIMARY KEY (id);


--
-- Name: assets assets_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_pkey PRIMARY KEY (id);


--
-- Name: assets assets_sha256_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_sha256_unique UNIQUE (sha256);


--
-- Name: auth_identities auth_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_pkey PRIMARY KEY (id);


--
-- Name: auth_identities auth_identities_provider_provider_subject_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_provider_provider_subject_unique UNIQUE (provider, provider_subject);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: campaigns campaigns_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.campaigns
    ADD CONSTRAINT campaigns_pkey PRIMARY KEY (id);


--
-- Name: cart_items cart_items_cart_id_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cart_items
    ADD CONSTRAINT cart_items_cart_id_product_id_unique UNIQUE (cart_id, product_id);


--
-- Name: cart_items cart_items_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cart_items
    ADD CONSTRAINT cart_items_pkey PRIMARY KEY (id);


--
-- Name: carts carts_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.carts
    ADD CONSTRAINT carts_pkey PRIMARY KEY (id);


--
-- Name: carts carts_token_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.carts
    ADD CONSTRAINT carts_token_unique UNIQUE (token);


--
-- Name: coupons coupons_code_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_code_unique UNIQUE (code);


--
-- Name: coupons coupons_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.coupons
    ADD CONSTRAINT coupons_pkey PRIMARY KEY (id);


--
-- Name: crawl_runs crawl_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawl_runs
    ADD CONSTRAINT crawl_runs_pkey PRIMARY KEY (id);


--
-- Name: crawled_pages crawled_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_pkey PRIMARY KEY (id);


--
-- Name: crawled_pages crawled_pages_run_id_path_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_run_id_path_unique UNIQUE (run_id, path);


--
-- Name: curriculum_aliases curriculum_aliases_entity_norm_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.curriculum_aliases
    ADD CONSTRAINT curriculum_aliases_entity_norm_unique UNIQUE (entity_type, entity_id, normalized_alias);


--
-- Name: curriculum_aliases curriculum_aliases_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.curriculum_aliases
    ADD CONSTRAINT curriculum_aliases_pkey PRIMARY KEY (id);


--
-- Name: customer_sessions customer_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customer_sessions
    ADD CONSTRAINT customer_sessions_pkey PRIMARY KEY (id);


--
-- Name: customer_sessions customer_sessions_token_hash_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customer_sessions
    ADD CONSTRAINT customer_sessions_token_hash_unique UNIQUE (token_hash);


--
-- Name: customers customers_email_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_email_unique UNIQUE (email);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: design_component_versions design_component_versions_component_id_version_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_component_versions
    ADD CONSTRAINT design_component_versions_component_id_version_unique UNIQUE (component_id, version);


--
-- Name: design_component_versions design_component_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_component_versions
    ADD CONSTRAINT design_component_versions_pkey PRIMARY KEY (id);


--
-- Name: design_components design_components_component_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_components
    ADD CONSTRAINT design_components_component_key_unique UNIQUE (component_key);


--
-- Name: design_components design_components_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_components
    ADD CONSTRAINT design_components_pkey PRIMARY KEY (id);


--
-- Name: design_heroes design_heroes_hero_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_heroes
    ADD CONSTRAINT design_heroes_hero_key_unique UNIQUE (hero_key);


--
-- Name: design_heroes design_heroes_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_heroes
    ADD CONSTRAINT design_heroes_pkey PRIMARY KEY (id);


--
-- Name: download_events download_events_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_events
    ADD CONSTRAINT download_events_pkey PRIMARY KEY (id);


--
-- Name: download_grants download_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_grants
    ADD CONSTRAINT download_grants_pkey PRIMARY KEY (id);


--
-- Name: download_grants download_grants_token_hash_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_grants
    ADD CONSTRAINT download_grants_token_hash_unique UNIQUE (token_hash);


--
-- Name: entitlements entitlements_order_item_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_order_item_id_unique UNIQUE (order_item_id);


--
-- Name: entitlements entitlements_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: features features_name_scope_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.features
    ADD CONSTRAINT features_name_scope_unique UNIQUE (name, scope);


--
-- Name: features features_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.features
    ADD CONSTRAINT features_pkey PRIMARY KEY (id);


--
-- Name: grades grades_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT grades_pkey PRIMARY KEY (id);


--
-- Name: grades grades_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT grades_slug_unique UNIQUE (slug);


--
-- Name: internal_links internal_links_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.internal_links
    ADD CONSTRAINT internal_links_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_number_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_number_unique UNIQUE (number);


--
-- Name: invoices invoices_order_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_order_id_unique UNIQUE (order_id);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: kinds kinds_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.kinds
    ADD CONSTRAINT kinds_pkey PRIMARY KEY (id);


--
-- Name: kinds kinds_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.kinds
    ADD CONSTRAINT kinds_slug_unique UNIQUE (slug);


--
-- Name: locales locales_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.locales
    ADD CONSTRAINT locales_pkey PRIMARY KEY (code);


--
-- Name: magic_links magic_links_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.magic_links
    ADD CONSTRAINT magic_links_pkey PRIMARY KEY (id);


--
-- Name: magic_links magic_links_token_hash_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.magic_links
    ADD CONSTRAINT magic_links_token_hash_unique UNIQUE (token_hash);


--
-- Name: material_requests material_requests_fingerprint_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_fingerprint_unique UNIQUE (fingerprint);


--
-- Name: material_requests material_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: navigation_menus navigation_menus_location_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT navigation_menus_location_unique UNIQUE (location);


--
-- Name: navigation_menus navigation_menus_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT navigation_menus_pkey PRIMARY KEY (id);


--
-- Name: navigation_publish_operations navigation_publish_operations_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_publish_operations
    ADD CONSTRAINT navigation_publish_operations_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: navigation_publish_operations navigation_publish_operations_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_publish_operations
    ADD CONSTRAINT navigation_publish_operations_pkey PRIMARY KEY (id);


--
-- Name: navigation_revisions navigation_revisions_menu_id_revision_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_revisions
    ADD CONSTRAINT navigation_revisions_menu_id_revision_unique UNIQUE (menu_id, revision);


--
-- Name: navigation_revisions navigation_revisions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_revisions
    ADD CONSTRAINT navigation_revisions_pkey PRIMARY KEY (id);


--
-- Name: not_found_hits not_found_hits_path_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.not_found_hits
    ADD CONSTRAINT not_found_hits_path_unique UNIQUE (path);


--
-- Name: not_found_hits not_found_hits_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.not_found_hits
    ADD CONSTRAINT not_found_hits_pkey PRIMARY KEY (id);


--
-- Name: not_found_hits not_found_hits_url_hash_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.not_found_hits
    ADD CONSTRAINT not_found_hits_url_hash_unique UNIQUE (url_hash);


--
-- Name: order_items order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_pkey PRIMARY KEY (id);


--
-- Name: orders orders_number_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_number_unique UNIQUE (number);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: outbox_events outbox_events_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.outbox_events
    ADD CONSTRAINT outbox_events_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: outbox_events outbox_events_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.outbox_events
    ADD CONSTRAINT outbox_events_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: payment_events payment_events_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_pkey PRIMARY KEY (id);


--
-- Name: payments payments_idempotency_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_idempotency_key_unique UNIQUE (idempotency_key);


--
-- Name: payments payments_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_pkey PRIMARY KEY (id);


--
-- Name: product_authors product_authors_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_authors
    ADD CONSTRAINT product_authors_pkey PRIMARY KEY (id);


--
-- Name: product_fact_evidence product_fact_evidence_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_fact_evidence
    ADD CONSTRAINT product_fact_evidence_pkey PRIMARY KEY (id);


--
-- Name: product_facts product_facts_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_facts
    ADD CONSTRAINT product_facts_pkey PRIMARY KEY (id);


--
-- Name: product_facts product_facts_product_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_facts
    ADD CONSTRAINT product_facts_product_id_key_unique UNIQUE (product_id, key);


--
-- Name: product_faqs product_faqs_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_faqs
    ADD CONSTRAINT product_faqs_pkey PRIMARY KEY (id);


--
-- Name: product_files product_files_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_files
    ADD CONSTRAINT product_files_pkey PRIMARY KEY (id);


--
-- Name: product_files product_files_product_id_asset_id_role_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_files
    ADD CONSTRAINT product_files_product_id_asset_id_role_unique UNIQUE (product_id, asset_id, role);


--
-- Name: product_knowledge_facts product_knowledge_facts_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_knowledge_facts
    ADD CONSTRAINT product_knowledge_facts_pkey PRIMARY KEY (id);


--
-- Name: product_knowledge_facts product_knowledge_facts_product_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_knowledge_facts
    ADD CONSTRAINT product_knowledge_facts_product_id_key_unique UNIQUE (product_id, key);


--
-- Name: product_layouts product_layouts_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_layouts
    ADD CONSTRAINT product_layouts_pkey PRIMARY KEY (id);


--
-- Name: product_layouts product_layouts_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_layouts
    ADD CONSTRAINT product_layouts_product_id_unique UNIQUE (product_id);


--
-- Name: product_media product_media_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_media
    ADD CONSTRAINT product_media_pkey PRIMARY KEY (id);


--
-- Name: product_prices product_prices_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_prices
    ADD CONSTRAINT product_prices_pkey PRIMARY KEY (id);


--
-- Name: product_seo product_seo_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_seo
    ADD CONSTRAINT product_seo_pkey PRIMARY KEY (id);


--
-- Name: product_seo product_seo_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_seo
    ADD CONSTRAINT product_seo_product_id_unique UNIQUE (product_id);


--
-- Name: product_translations product_translations_locale_code_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations
    ADD CONSTRAINT product_translations_locale_code_slug_unique UNIQUE (locale_code, slug);


--
-- Name: product_translations product_translations_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations
    ADD CONSTRAINT product_translations_pkey PRIMARY KEY (id);


--
-- Name: product_translations product_translations_product_id_locale_code_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations
    ADD CONSTRAINT product_translations_product_id_locale_code_unique UNIQUE (product_id, locale_code);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: products products_sku_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_sku_unique UNIQUE (sku);


--
-- Name: products products_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_slug_unique UNIQUE (slug);


--
-- Name: quote_requests quote_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.quote_requests
    ADD CONSTRAINT quote_requests_pkey PRIMARY KEY (id);


--
-- Name: redirect_rules redirect_rules_from_path_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.redirect_rules
    ADD CONSTRAINT redirect_rules_from_path_unique UNIQUE (from_path);


--
-- Name: redirect_rules redirect_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.redirect_rules
    ADD CONSTRAINT redirect_rules_pkey PRIMARY KEY (id);


--
-- Name: refunds refunds_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_pkey PRIMARY KEY (id);


--
-- Name: review_aggregates review_aggregates_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_aggregates
    ADD CONSTRAINT review_aggregates_pkey PRIMARY KEY (id);


--
-- Name: review_aggregates review_aggregates_product_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_aggregates
    ADD CONSTRAINT review_aggregates_product_id_unique UNIQUE (product_id);


--
-- Name: review_category_scores review_category_scores_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_category_scores
    ADD CONSTRAINT review_category_scores_pkey PRIMARY KEY (id);


--
-- Name: review_category_scores review_category_scores_review_id_criterion_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_category_scores
    ADD CONSTRAINT review_category_scores_review_id_criterion_unique UNIQUE (review_id, criterion);


--
-- Name: review_media review_media_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_media
    ADD CONSTRAINT review_media_pkey PRIMARY KEY (id);


--
-- Name: review_media review_media_review_id_asset_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_media
    ADD CONSTRAINT review_media_review_id_asset_id_unique UNIQUE (review_id, asset_id);


--
-- Name: review_replies review_replies_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_replies
    ADD CONSTRAINT review_replies_pkey PRIMARY KEY (id);


--
-- Name: review_replies review_replies_review_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_replies
    ADD CONSTRAINT review_replies_review_id_unique UNIQUE (review_id);


--
-- Name: review_votes review_votes_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_votes
    ADD CONSTRAINT review_votes_pkey PRIMARY KEY (id);


--
-- Name: review_votes review_votes_review_id_customer_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_votes
    ADD CONSTRAINT review_votes_review_id_customer_id_unique UNIQUE (review_id, customer_id);


--
-- Name: reviews reviews_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_pkey PRIMARY KEY (id);


--
-- Name: reviews reviews_product_id_customer_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_product_id_customer_id_unique UNIQUE (product_id, customer_id);


--
-- Name: search_index_state search_index_state_engine_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_index_state
    ADD CONSTRAINT search_index_state_engine_unique UNIQUE (engine);


--
-- Name: search_index_state search_index_state_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_index_state
    ADD CONSTRAINT search_index_state_pkey PRIMARY KEY (id);


--
-- Name: search_queries search_queries_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_queries
    ADD CONSTRAINT search_queries_pkey PRIMARY KEY (id);


--
-- Name: search_synonyms search_synonyms_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_synonyms
    ADD CONSTRAINT search_synonyms_pkey PRIMARY KEY (id);


--
-- Name: search_synonyms search_synonyms_term_synonym_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_synonyms
    ADD CONSTRAINT search_synonyms_term_synonym_unique UNIQUE (term, synonym);


--
-- Name: semesters semesters_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.semesters
    ADD CONSTRAINT semesters_pkey PRIMARY KEY (id);


--
-- Name: semesters semesters_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.semesters
    ADD CONSTRAINT semesters_slug_unique UNIQUE (slug);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: settings settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_key_unique UNIQUE (key);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (id);


--
-- Name: sitemap_url_states sitemap_url_states_loc_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.sitemap_url_states
    ADD CONSTRAINT sitemap_url_states_loc_unique UNIQUE (loc);


--
-- Name: sitemap_url_states sitemap_url_states_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.sitemap_url_states
    ADD CONSTRAINT sitemap_url_states_pkey PRIMARY KEY (id);


--
-- Name: stages stages_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.stages
    ADD CONSTRAINT stages_pkey PRIMARY KEY (id);


--
-- Name: stages stages_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.stages
    ADD CONSTRAINT stages_slug_unique UNIQUE (slug);


--
-- Name: studio_entry_prefs studio_entry_prefs_admin_user_id_registry_id_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_entry_prefs
    ADD CONSTRAINT studio_entry_prefs_admin_user_id_registry_id_unique UNIQUE (admin_user_id, registry_id);


--
-- Name: studio_entry_prefs studio_entry_prefs_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_entry_prefs
    ADD CONSTRAINT studio_entry_prefs_pkey PRIMARY KEY (id);


--
-- Name: studio_media studio_media_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_media
    ADD CONSTRAINT studio_media_pkey PRIMARY KEY (id);


--
-- Name: studio_media studio_media_url_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_media
    ADD CONSTRAINT studio_media_url_unique UNIQUE (url);


--
-- Name: studio_page_versions studio_page_versions_page_id_version_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_page_versions
    ADD CONSTRAINT studio_page_versions_page_id_version_unique UNIQUE (page_id, version);


--
-- Name: studio_page_versions studio_page_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_page_versions
    ADD CONSTRAINT studio_page_versions_pkey PRIMARY KEY (id);


--
-- Name: studio_pages studio_pages_locale_page_key_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_pages
    ADD CONSTRAINT studio_pages_locale_page_key_unique UNIQUE (locale, page_key);


--
-- Name: studio_pages studio_pages_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_pages
    ADD CONSTRAINT studio_pages_pkey PRIMARY KEY (id);


--
-- Name: studio_symbol_versions studio_symbol_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbol_versions
    ADD CONSTRAINT studio_symbol_versions_pkey PRIMARY KEY (id);


--
-- Name: studio_symbol_versions studio_symbol_versions_symbol_id_version_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbol_versions
    ADD CONSTRAINT studio_symbol_versions_symbol_id_version_unique UNIQUE (symbol_id, version);


--
-- Name: studio_symbols studio_symbols_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbols
    ADD CONSTRAINT studio_symbols_pkey PRIMARY KEY (id);


--
-- Name: studio_templates studio_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_templates
    ADD CONSTRAINT studio_templates_pkey PRIMARY KEY (id);


--
-- Name: subjects subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.subjects
    ADD CONSTRAINT subjects_pkey PRIMARY KEY (id);


--
-- Name: subjects subjects_slug_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.subjects
    ADD CONSTRAINT subjects_slug_unique UNIQUE (slug);


--
-- Name: theme_versions theme_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.theme_versions
    ADD CONSTRAINT theme_versions_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: admin_audit_log_action_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX admin_audit_log_action_created_at_index ON public.admin_audit_log USING btree (action, created_at);


--
-- Name: admin_audit_log_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX admin_audit_log_entity_type_entity_id_index ON public.admin_audit_log USING btree (entity_type, entity_id);


--
-- Name: admin_notes_entity_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX admin_notes_entity_index ON public.admin_notes USING btree (entity_type, entity_id, id);


--
-- Name: ai_requests_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX ai_requests_created_at_index ON public.ai_requests USING btree (created_at);


--
-- Name: ai_requests_model_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX ai_requests_model_created_at_index ON public.ai_requests USING btree (model, created_at);


--
-- Name: ai_requests_status_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX ai_requests_status_created_at_index ON public.ai_requests USING btree (status, created_at);


--
-- Name: analytics_events_event_type_occurred_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX analytics_events_event_type_occurred_at_index ON public.analytics_events USING btree (event_type, occurred_at);


--
-- Name: analytics_events_occurred_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX analytics_events_occurred_at_index ON public.analytics_events USING btree (occurred_at);


--
-- Name: analytics_events_product_id_event_type_occurred_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX analytics_events_product_id_event_type_occurred_at_index ON public.analytics_events USING btree (product_id, event_type, occurred_at);


--
-- Name: asset_extractions_asset_sha256_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX asset_extractions_asset_sha256_index ON public.asset_extractions USING btree (asset_sha256);


--
-- Name: asset_extractions_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX asset_extractions_status_index ON public.asset_extractions USING btree (status);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: campaigns_ends_at_starts_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX campaigns_ends_at_starts_at_index ON public.campaigns USING btree (ends_at, starts_at);


--
-- Name: crawled_pages_run_id_depth_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX crawled_pages_run_id_depth_index ON public.crawled_pages USING btree (run_id, depth);


--
-- Name: crawled_pages_run_id_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX crawled_pages_run_id_status_index ON public.crawled_pages USING btree (run_id, status);


--
-- Name: curriculum_aliases_entity_type_entity_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX curriculum_aliases_entity_type_entity_id_index ON public.curriculum_aliases USING btree (entity_type, entity_id);


--
-- Name: curriculum_aliases_normalized_alias_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX curriculum_aliases_normalized_alias_index ON public.curriculum_aliases USING btree (normalized_alias);


--
-- Name: design_components_component_type_published_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX design_components_component_type_published_at_index ON public.design_components USING btree (component_type, published_at);


--
-- Name: design_heroes_target_type_target_ref_published_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX design_heroes_target_type_target_ref_published_at_index ON public.design_heroes USING btree (target_type, target_ref, published_at);


--
-- Name: download_grants_asset_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX download_grants_asset_id_index ON public.download_grants USING btree (asset_id);


--
-- Name: entitlements_customer_id_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX entitlements_customer_id_status_index ON public.entitlements USING btree (customer_id, status);


--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);


--
-- Name: internal_links_run_id_from_path_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX internal_links_run_id_from_path_index ON public.internal_links USING btree (run_id, from_path);


--
-- Name: internal_links_run_id_to_path_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX internal_links_run_id_to_path_index ON public.internal_links USING btree (run_id, to_path);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: locales_is_active_position_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX locales_is_active_position_index ON public.locales USING btree (is_active, "position");


--
-- Name: magic_links_email_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX magic_links_email_index ON public.magic_links USING btree (email);


--
-- Name: material_requests_last_seen_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX material_requests_last_seen_at_index ON public.material_requests USING btree (last_seen_at);


--
-- Name: material_requests_status_hits_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX material_requests_status_hits_index ON public.material_requests USING btree (status, hits);


--
-- Name: navigation_publish_operations_menu_id_started_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX navigation_publish_operations_menu_id_started_at_index ON public.navigation_publish_operations USING btree (menu_id, started_at);


--
-- Name: navigation_publish_operations_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX navigation_publish_operations_status_index ON public.navigation_publish_operations USING btree (status);


--
-- Name: navigation_revisions_menu_id_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX navigation_revisions_menu_id_created_at_index ON public.navigation_revisions USING btree (menu_id, created_at);


--
-- Name: orders_customer_id_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX orders_customer_id_status_index ON public.orders USING btree (customer_id, status);


--
-- Name: orders_reminder_scan; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX orders_reminder_scan ON public.orders USING btree (status, reminder_stage, created_at);


--
-- Name: outbox_events_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX outbox_events_created_at_index ON public.outbox_events USING btree (created_at);


--
-- Name: outbox_events_status_next_retry_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX outbox_events_status_next_retry_at_index ON public.outbox_events USING btree (status, next_retry_at);


--
-- Name: product_authors_product_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_authors_product_id_index ON public.product_authors USING btree (product_id);


--
-- Name: product_fact_evidence_asset_sha256_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_fact_evidence_asset_sha256_index ON public.product_fact_evidence USING btree (asset_sha256);


--
-- Name: product_fact_evidence_fact_id_asset_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_fact_evidence_fact_id_asset_id_index ON public.product_fact_evidence USING btree (fact_id, asset_id);


--
-- Name: product_fact_evidence_fact_id_contradicts_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_fact_evidence_fact_id_contradicts_index ON public.product_fact_evidence USING btree (fact_id, contradicts);


--
-- Name: product_faqs_product_id_position_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_faqs_product_id_position_index ON public.product_faqs USING btree (product_id, "position");


--
-- Name: product_knowledge_facts_verification_state_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_knowledge_facts_verification_state_index ON public.product_knowledge_facts USING btree (verification_state);


--
-- Name: product_translations_locale_code_status_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX product_translations_locale_code_status_index ON public.product_translations USING btree (locale_code, status);


--
-- Name: products_status_featured_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX products_status_featured_index ON public.products USING btree (status, featured);


--
-- Name: products_status_published_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX products_status_published_at_index ON public.products USING btree (status, published_at);


--
-- Name: quote_requests_email_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX quote_requests_email_created_at_index ON public.quote_requests USING btree (email, created_at);


--
-- Name: quote_requests_handled_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX quote_requests_handled_at_index ON public.quote_requests USING btree (handled_at);


--
-- Name: reviews_product_id_status_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX reviews_product_id_status_created_at_index ON public.reviews USING btree (product_id, status, created_at);


--
-- Name: reviews_product_id_status_helpful_count_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX reviews_product_id_status_helpful_count_index ON public.reviews USING btree (product_id, status, helpful_count);


--
-- Name: search_queries_created_at_provider_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX search_queries_created_at_provider_index ON public.search_queries USING btree (created_at, provider);


--
-- Name: search_queries_normalized_query_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX search_queries_normalized_query_index ON public.search_queries USING btree (normalized_query);


--
-- Name: search_queries_session_hash_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX search_queries_session_hash_created_at_index ON public.search_queries USING btree (session_hash, created_at);


--
-- Name: search_queries_zero_result_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX search_queries_zero_result_created_at_index ON public.search_queries USING btree (zero_result, created_at);


--
-- Name: search_synonyms_state_term_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX search_synonyms_state_term_index ON public.search_synonyms USING btree (state, term);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: sitemap_url_states_provider_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX sitemap_url_states_provider_index ON public.sitemap_url_states USING btree (provider);


--
-- Name: studio_entry_prefs_admin_user_id_last_opened_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX studio_entry_prefs_admin_user_id_last_opened_at_index ON public.studio_entry_prefs USING btree (admin_user_id, last_opened_at);


--
-- Name: studio_page_versions_page_id_kind_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX studio_page_versions_page_id_kind_created_at_index ON public.studio_page_versions USING btree (page_id, kind, created_at);


--
-- Name: studio_pages_published_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX studio_pages_published_at_index ON public.studio_pages USING btree (published_at);


--
-- Name: studio_symbol_versions_symbol_id_kind_created_at_index; Type: INDEX; Schema: public; Owner: tahdheer
--

CREATE INDEX studio_symbol_versions_symbol_id_kind_created_at_index ON public.studio_symbol_versions USING btree (symbol_id, kind, created_at);


--
-- Name: admin_audit_log admin_audit_log_admin_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_audit_log
    ADD CONSTRAINT admin_audit_log_admin_user_id_foreign FOREIGN KEY (admin_user_id) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: admin_notes admin_notes_admin_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.admin_notes
    ADD CONSTRAINT admin_notes_admin_user_id_foreign FOREIGN KEY (admin_user_id) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: ai_requests ai_requests_admin_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.ai_requests
    ADD CONSTRAINT ai_requests_admin_user_id_foreign FOREIGN KEY (admin_user_id) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: ai_requests ai_requests_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.ai_requests
    ADD CONSTRAINT ai_requests_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: analytics_events analytics_events_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_events
    ADD CONSTRAINT analytics_events_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE SET NULL;


--
-- Name: analytics_events analytics_events_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_events
    ADD CONSTRAINT analytics_events_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: analytics_product_daily analytics_product_daily_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.analytics_product_daily
    ADD CONSTRAINT analytics_product_daily_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: asset_extractions asset_extractions_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.asset_extractions
    ADD CONSTRAINT asset_extractions_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE SET NULL;


--
-- Name: auth_identities auth_identities_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: cart_items cart_items_cart_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cart_items
    ADD CONSTRAINT cart_items_cart_id_foreign FOREIGN KEY (cart_id) REFERENCES public.carts(id) ON DELETE CASCADE;


--
-- Name: cart_items cart_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.cart_items
    ADD CONSTRAINT cart_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: carts carts_coupon_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.carts
    ADD CONSTRAINT carts_coupon_id_foreign FOREIGN KEY (coupon_id) REFERENCES public.coupons(id) ON DELETE SET NULL;


--
-- Name: carts carts_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.carts
    ADD CONSTRAINT carts_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: crawled_pages crawled_pages_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.crawled_pages
    ADD CONSTRAINT crawled_pages_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.crawl_runs(id) ON DELETE CASCADE;


--
-- Name: customer_sessions customer_sessions_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.customer_sessions
    ADD CONSTRAINT customer_sessions_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: design_component_versions design_component_versions_component_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_component_versions
    ADD CONSTRAINT design_component_versions_component_id_foreign FOREIGN KEY (component_id) REFERENCES public.design_components(id) ON DELETE CASCADE;


--
-- Name: design_component_versions design_component_versions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_component_versions
    ADD CONSTRAINT design_component_versions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: design_components design_components_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_components
    ADD CONSTRAINT design_components_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: design_heroes design_heroes_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.design_heroes
    ADD CONSTRAINT design_heroes_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: download_events download_events_entitlement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_events
    ADD CONSTRAINT download_events_entitlement_id_foreign FOREIGN KEY (entitlement_id) REFERENCES public.entitlements(id) ON DELETE SET NULL;


--
-- Name: download_grants download_grants_entitlement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.download_grants
    ADD CONSTRAINT download_grants_entitlement_id_foreign FOREIGN KEY (entitlement_id) REFERENCES public.entitlements(id) ON DELETE CASCADE;


--
-- Name: entitlements entitlements_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: entitlements entitlements_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: entitlements entitlements_order_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_order_item_id_foreign FOREIGN KEY (order_item_id) REFERENCES public.order_items(id) ON DELETE CASCADE;


--
-- Name: entitlements entitlements_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.entitlements
    ADD CONSTRAINT entitlements_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: grades grades_stage_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.grades
    ADD CONSTRAINT grades_stage_id_foreign FOREIGN KEY (stage_id) REFERENCES public.stages(id) ON DELETE CASCADE;


--
-- Name: internal_links internal_links_run_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.internal_links
    ADD CONSTRAINT internal_links_run_id_foreign FOREIGN KEY (run_id) REFERENCES public.crawl_runs(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: material_requests material_requests_grade_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_grade_id_foreign FOREIGN KEY (grade_id) REFERENCES public.grades(id) ON DELETE SET NULL;


--
-- Name: material_requests material_requests_semester_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_semester_id_foreign FOREIGN KEY (semester_id) REFERENCES public.semesters(id) ON DELETE SET NULL;


--
-- Name: material_requests material_requests_stage_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_stage_id_foreign FOREIGN KEY (stage_id) REFERENCES public.stages(id) ON DELETE SET NULL;


--
-- Name: material_requests material_requests_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.material_requests
    ADD CONSTRAINT material_requests_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE SET NULL;


--
-- Name: navigation_menus navigation_menus_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT navigation_menus_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: navigation_publish_operations navigation_publish_operations_actor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_publish_operations
    ADD CONSTRAINT navigation_publish_operations_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: navigation_publish_operations navigation_publish_operations_menu_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_publish_operations
    ADD CONSTRAINT navigation_publish_operations_menu_id_foreign FOREIGN KEY (menu_id) REFERENCES public.navigation_menus(id) ON DELETE CASCADE;


--
-- Name: navigation_revisions navigation_revisions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_revisions
    ADD CONSTRAINT navigation_revisions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: navigation_revisions navigation_revisions_menu_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.navigation_revisions
    ADD CONSTRAINT navigation_revisions_menu_id_foreign FOREIGN KEY (menu_id) REFERENCES public.navigation_menus(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: order_items order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: orders orders_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: payment_events payment_events_payment_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payment_events
    ADD CONSTRAINT payment_events_payment_id_foreign FOREIGN KEY (payment_id) REFERENCES public.payments(id) ON DELETE CASCADE;


--
-- Name: payments payments_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.payments
    ADD CONSTRAINT payments_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: product_authors product_authors_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_authors
    ADD CONSTRAINT product_authors_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_fact_evidence product_fact_evidence_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_fact_evidence
    ADD CONSTRAINT product_fact_evidence_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE SET NULL;


--
-- Name: product_fact_evidence product_fact_evidence_fact_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_fact_evidence
    ADD CONSTRAINT product_fact_evidence_fact_id_foreign FOREIGN KEY (fact_id) REFERENCES public.product_knowledge_facts(id) ON DELETE CASCADE;


--
-- Name: product_facts product_facts_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_facts
    ADD CONSTRAINT product_facts_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_faqs product_faqs_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_faqs
    ADD CONSTRAINT product_faqs_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_files product_files_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_files
    ADD CONSTRAINT product_files_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE RESTRICT;


--
-- Name: product_files product_files_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_files
    ADD CONSTRAINT product_files_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_knowledge_facts product_knowledge_facts_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_knowledge_facts
    ADD CONSTRAINT product_knowledge_facts_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_layouts product_layouts_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_layouts
    ADD CONSTRAINT product_layouts_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_layouts product_layouts_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_layouts
    ADD CONSTRAINT product_layouts_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: product_media product_media_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_media
    ADD CONSTRAINT product_media_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_prices product_prices_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_prices
    ADD CONSTRAINT product_prices_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_seo product_seo_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_seo
    ADD CONSTRAINT product_seo_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_translations product_translations_locale_code_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations
    ADD CONSTRAINT product_translations_locale_code_foreign FOREIGN KEY (locale_code) REFERENCES public.locales(code) ON DELETE CASCADE;


--
-- Name: product_translations product_translations_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.product_translations
    ADD CONSTRAINT product_translations_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: products products_grade_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_grade_id_foreign FOREIGN KEY (grade_id) REFERENCES public.grades(id) ON DELETE SET NULL;


--
-- Name: products products_replacement_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_replacement_product_id_foreign FOREIGN KEY (replacement_product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: products products_semester_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_semester_id_foreign FOREIGN KEY (semester_id) REFERENCES public.semesters(id) ON DELETE SET NULL;


--
-- Name: products products_stage_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_stage_id_foreign FOREIGN KEY (stage_id) REFERENCES public.stages(id) ON DELETE SET NULL;


--
-- Name: products products_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE SET NULL;


--
-- Name: quote_requests quote_requests_handled_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.quote_requests
    ADD CONSTRAINT quote_requests_handled_by_foreign FOREIGN KEY (handled_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: quote_requests quote_requests_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.quote_requests
    ADD CONSTRAINT quote_requests_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: refunds refunds_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.refunds
    ADD CONSTRAINT refunds_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: review_aggregates review_aggregates_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_aggregates
    ADD CONSTRAINT review_aggregates_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: review_category_scores review_category_scores_review_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_category_scores
    ADD CONSTRAINT review_category_scores_review_id_foreign FOREIGN KEY (review_id) REFERENCES public.reviews(id) ON DELETE CASCADE;


--
-- Name: review_media review_media_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_media
    ADD CONSTRAINT review_media_asset_id_foreign FOREIGN KEY (asset_id) REFERENCES public.assets(id) ON DELETE CASCADE;


--
-- Name: review_media review_media_review_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_media
    ADD CONSTRAINT review_media_review_id_foreign FOREIGN KEY (review_id) REFERENCES public.reviews(id) ON DELETE CASCADE;


--
-- Name: review_replies review_replies_review_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_replies
    ADD CONSTRAINT review_replies_review_id_foreign FOREIGN KEY (review_id) REFERENCES public.reviews(id) ON DELETE CASCADE;


--
-- Name: review_votes review_votes_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_votes
    ADD CONSTRAINT review_votes_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: review_votes review_votes_review_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.review_votes
    ADD CONSTRAINT review_votes_review_id_foreign FOREIGN KEY (review_id) REFERENCES public.reviews(id) ON DELETE CASCADE;


--
-- Name: reviews reviews_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: reviews reviews_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE SET NULL;


--
-- Name: reviews reviews_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.reviews
    ADD CONSTRAINT reviews_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: search_queries search_queries_clicked_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.search_queries
    ADD CONSTRAINT search_queries_clicked_product_id_foreign FOREIGN KEY (clicked_product_id) REFERENCES public.products(id) ON DELETE SET NULL;


--
-- Name: studio_entry_prefs studio_entry_prefs_admin_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_entry_prefs
    ADD CONSTRAINT studio_entry_prefs_admin_user_id_foreign FOREIGN KEY (admin_user_id) REFERENCES public.admin_users(id) ON DELETE CASCADE;


--
-- Name: studio_media studio_media_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_media
    ADD CONSTRAINT studio_media_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: studio_page_versions studio_page_versions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_page_versions
    ADD CONSTRAINT studio_page_versions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: studio_page_versions studio_page_versions_page_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_page_versions
    ADD CONSTRAINT studio_page_versions_page_id_foreign FOREIGN KEY (page_id) REFERENCES public.studio_pages(id) ON DELETE CASCADE;


--
-- Name: studio_pages studio_pages_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_pages
    ADD CONSTRAINT studio_pages_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: studio_symbol_versions studio_symbol_versions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbol_versions
    ADD CONSTRAINT studio_symbol_versions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: studio_symbol_versions studio_symbol_versions_symbol_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbol_versions
    ADD CONSTRAINT studio_symbol_versions_symbol_id_foreign FOREIGN KEY (symbol_id) REFERENCES public.studio_symbols(id) ON DELETE CASCADE;


--
-- Name: studio_symbols studio_symbols_updated_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_symbols
    ADD CONSTRAINT studio_symbols_updated_by_foreign FOREIGN KEY (updated_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: studio_templates studio_templates_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.studio_templates
    ADD CONSTRAINT studio_templates_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- Name: theme_versions theme_versions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: tahdheer
--

ALTER TABLE ONLY public.theme_versions
    ADD CONSTRAINT theme_versions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.admin_users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict LxATCc5EABoXyMNY0nhXT2tAKbfsRSLfyvWMuHVwxjEYsq52tz89Jb7TBFSwxcS

