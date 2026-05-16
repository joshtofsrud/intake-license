{{--
  Shared subnav partial for the Reports tabs.
  Usage:  @include('tenant.reports._tab_subnav', ['active' => 'services'])
  Active values: operations | customers | services | retail | money | staff
--}}
<nav class="rep-toggle" style="margin-bottom: 18px;">
  <a href="{{ route('tenant.reports.index',     ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'operations' ? 'active' : '' }}">Operations</a>
  <a href="{{ route('tenant.reports.customers', ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'customers'  ? 'active' : '' }}">Customers</a>
  <a href="{{ route('tenant.reports.services',  ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'services'   ? 'active' : '' }}">Services</a>
  <a href="{{ route('tenant.reports.retail',    ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'retail'     ? 'active' : '' }}">Retail</a>
  <a href="{{ route('tenant.reports.money',     ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'money'      ? 'active' : '' }}">Money</a>
  <a href="{{ route('tenant.reports.staff',     ['subdomain' => tenant()->subdomain]) }}" class="{{ ($active ?? '') === 'staff'      ? 'active' : '' }}">Staff</a>
</nav>
